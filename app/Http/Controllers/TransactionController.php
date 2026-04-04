<?php

namespace App\Http\Controllers;

use App\Exports\TransactionsExport;
use App\Models\Log;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Transaction;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Transaction::with('user');

        if ($user->role === 'cashier') {
            $query->where('user_id', $user->id);
        } else {
            if ($request->filled('cashier_id')) {
                $cashierIds = explode(',', $request->cashier_id);
                $query->whereIn('user_id', $cashierIds);
            }
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('transaction_date', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59'
            ]);
        } elseif ($request->filled('start_date')) {
            $query->whereDate('transaction_date', '>=', $request->start_date);
        } elseif ($request->filled('end_date')) {
            $query->whereDate('transaction_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            // Ubah ketikan user dari Flutter menjadi huruf kecil semua
            $search = strtolower($request->search);

            $query->where(function($q) use ($search) {
                // Gunakan LOWER() SQL untuk menyamakan format kolom database
                $q->whereRaw('LOWER(trx_no) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(customer_name) LIKE ?', ["%{$search}%"]);
            });
        }

        $sort = $request->sort ?? 'newest';
        switch ($sort) {
            case 'oldest':
                $query->orderBy('transaction_date', 'asc');
                break;
            case 'highest':
                $query->orderBy('total_amount', 'desc');
                break;
            case 'lowest':
                $query->orderBy('total_amount', 'asc');
                break;
            case 'newest':
            default:
                $query->orderBy('transaction_date', 'desc');
                break;
        }

        $transactions = $query->paginate(20);

        $formattedData = $transactions->getCollection()->map(function ($trx) {
            return [
                'id' => $trx->id,
                'trx_no' => $trx->trx_no,
                'date' => Carbon::parse($trx->transaction_date)->toIso8601String(),
                'cashier' => $trx->user ? $trx->user->name : 'Umum',
                'cashier_username' => $trx->user ? $trx->user->username : 'kasir',
                'total' => $trx->total_amount,
                'status' => $trx->status,
            ];
        });

        $transactions->setCollection($formattedData);

        return response()->json([
            'status' => 'success',
            'data' => $transactions
        ], 200);
    }

    public function indexCashier()
    {
        return response()->json(
            [
                'status' => true,
                'message' => 'GET data sukses',
                'data' => User::where('role', 'cashier')->get(),
            ],
            200,
        );
    }

    public function export(Request $request)
    {
        $query = Transaction::with('user');

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('transaction_date', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59'
            ]);
        } elseif ($request->filled('start_date')) {
            $query->whereDate('transaction_date', '>=', $request->start_date);
        } elseif ($request->filled('end_date')) {
            $query->whereDate('transaction_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            // Ubah ketikan user dari Flutter menjadi huruf kecil semua
            $search = strtolower($request->search);

            $query->where(function($q) use ($search) {
                // Gunakan LOWER() SQL untuk menyamakan format kolom database
                $q->whereRaw('LOWER(trx_no) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(customer_name) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($request->filled('cashier_id')) {
            $cashierIds = explode(',', $request->cashier_id);
            $query->whereIn('user_id', $cashierIds);
        }

        // --- PERCABANGAN FORMAT (EXCEL ATAU PDF) ---
        if ($request->format === 'excel') {
            // Kita oper $query, $request->start_date, dan $request->end_date ke dalam Export Class
            return Excel::download(
                new TransactionsExport($query, $request->start_date, $request->end_date),
                'Laporan_Transaksi_Healink.xlsx'
            );
        }

        if ($request->format === 'pdf') {
            // Tarik data menggunakan get() HANYA untuk PDF
            $transactions = $query->orderBy('transaction_date', 'desc')->get();
            $pdf = Pdf::loadView('exports.transactions', compact('transactions'));
            return $pdf->download('Laporan_Transaksi.pdf');
        }

        return response()->json(['message' => 'Format tidak didukung'], 400);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name'      => 'nullable|string|max:255',
            'note'               => 'nullable|string',
            'paid_amount'        => 'required|numeric|min:0',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_id'   => 'required|exists:product_batches,id',
            'items.*.qty'        => 'required|integer|min:1',
        ],
        [
            'paid_amount.required'        => 'Nominal uang yang dibayarkan harus diisi.',
            'paid_amount.numeric'         => 'Nominal uang harus berupa angka.',
            'paid_amount.min'             => 'Nominal uang tidak boleh minus.',
            'items.required'              => 'Keranjang belanja tidak boleh kosong.',
            'items.array'                 => 'Format keranjang belanja tidak valid.',
            'items.min'                   => 'Minimal harus ada 1 barang di keranjang.',
            'items.*.product_id.required' => 'ID Produk hilang pada salah satu item keranjang.',
            'items.*.product_id.exists'   => 'Ada produk di keranjang yang sudah tidak tersedia di database.',
            'items.*.batch_id.required'   => 'ID Batch hilang pada salah satu item keranjang.',
            'items.*.batch_id.exists'     => 'Ada batch produk di keranjang yang sudah tidak valid.',
            'items.*.qty.required'        => 'Jumlah barang (Qty) harus diisi pada semua item.',
            'items.*.qty.integer'         => 'Jumlah barang (Qty) harus berupa angka bulat.',
            'items.*.qty.min'             => 'Jumlah barang minimal adalah 1.',
        ]);

        $user = Auth::user();

        try {
            DB::beginTransaction();

            $subtotal = 0;
            $itemsToInsert = [];

            $batchIds = collect($data['items'])->pluck('batch_id')->unique();
            $batches = ProductBatch::whereIn('id', $batchIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($data['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                $batch = $batches[$item['batch_id']] ?? null;

                if (!$batch || $batch->product_id !== (int)$item['product_id']) {
                    throw new \Exception("Batch tidak valid untuk produk ini.");
                }

                if ($batch->exp_date && Carbon::parse($batch->exp_date)->startOfDay()->lt(now()->startOfDay())) {
                    throw new \Exception("Batch {$batch->batch_number} untuk produk {$product->product_name} sudah kadaluarsa (Exp: {$batch->exp_date}).");
                }

                if ($batch->stock < $item['qty']) {
                    throw new \Exception("Stok tidak mencukupi untuk produk: {$product->product_name} (Batch: {$batch->batch_number}). Sisa stok: {$batch->stock}");
                }

                $itemSubtotal = $product->sell_price * $item['qty'];
                $subtotal += $itemSubtotal;

                $itemsToInsert[] = [
                    'product_id'   => $product->id,
                    'batch_id'     => $batch->id,
                    'product_name' => $product->product_name,
                    'qty'          => $item['qty'],
                    'unit_price'   => $product->sell_price,
                    'subtotal'     => $itemSubtotal,
                ];
            }

            if ($request->paid_amount < $subtotal) {
                throw new \Exception("Uang yang dibayarkan (Rp " . number_format($request->paid_amount, 0, ',', '.') . ") kurang dari total belanja (Rp " . number_format($subtotal, 0, ',', '.') . ").");
            }

            $changeAmount = $request->paid_amount - $subtotal;
            $trxNo = 'TRX-' . now()->format('ymd') . '-' . strtoupper(Str::random(6));

            $transaction = Transaction::create([
                'trx_no'           => $trxNo,
                'user_id'          => $user->id,
                'customer_name'    => $request->customer_name,
                'subtotal'         => $subtotal,
                'total_amount'     => $subtotal,
                'paid_amount'      => $request->paid_amount,
                'change_amount'    => $changeAmount,
                'transaction_date' => now(),
                'status'           => 'sale',
                'note'             => $request->note,
            ]);

            foreach ($itemsToInsert as $itemData) {
                $transaction->items()->create($itemData);

                $batch = $batches[$itemData['batch_id']];
                $batch->decrement('stock', $itemData['qty']);
            }

            DB::commit();

            Log::create([
                'user_id' => $user->id,
                'activity' => 'Buat transaksi',
                'detail' => $user->name . ' membuat transaksi ' . $trxNo . ' dengan total Rp' . number_format($subtotal, 0, ',', '.'),
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Transaksi berhasil diproses!',
                'data'    => $transaction->load('items'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function show($id)
    {
        try {

            $transaction = Transaction::with(['user', 'items.product'])->findOrFail($id);

            $user = Auth::user();

            if ($user->role === 'cashier' && $transaction->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak. Anda tidak diizinkan melihat transaksi kasir lain.'
                ], 403);
            }

            $formattedData = [
                'id'               => $transaction->id,
                'trx_no'           => $transaction->trx_no,
                'date'             => Carbon::parse($transaction->transaction_date)->toIso8601String(),
                'cashier'          => $transaction->user ? $transaction->user->name : 'Umum',
                'customer_name'    => $transaction->customer_name,
                'note'             => $transaction->note,
                'subtotal'         => $transaction->subtotal,
                'total_amount'     => $transaction->total_amount,
                'paid_amount'      => $transaction->paid_amount,
                'change_amount'    => $transaction->change_amount,
                'status'           => $transaction->status,
                'void_reason'      => $transaction->void_reason,

                'items'            => $transaction->items->map(function ($item) {
                    return [
                        'id'           => $item->id,
                        'product_id'   => $item->product_id,
                        'product_name' => $item->product_name,
                        'qty'          => $item->qty,
                        'price'        => $item->unit_price,
                        'subtotal'     => $item->subtotal,
                        'batch_id'     => $item->batch_id,
                        'batch_number' => $item->batch ? $item->batch->batch_number : '-',
                        'image_url'    => $item->product ? $item->product->image_url : null,
                    ];
                })
            ];

            return response()->json([
                'success' => true,
                'data'    => $formattedData
            ], 200);

        } catch (ModelNotFoundException $e) {
            // Jika ID tidak ditemukan di database
            return response()->json([
                'success' => false,
                'message' => 'Transaksi tidak ditemukan.'
            ], 404);
        } catch (\Exception $e) {
            // Error umum lainnya
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();

        $transaction = Transaction::findOrFail($id);

        if ($user->role === 'cashier' && $transaction->user_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $data = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'note' => 'nullable|string',
        ],
        [
            'customer_name.string' => 'Nama pelanggan tidak valid.',
            'customer_name.max' => 'Nama pelanggan mencapai batas karakter maksimal.',
            'note.string' => 'Catatan tidak valid.',
        ]);

        DB::beginTransaction();

        try {
            if ($transaction->status === 'void') {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaksi yang dibatalkan tidak dapat diubah.'
                ], 400);
            }

            $transaction->update([
                'customer_name' => $data['customer_name'] ?? $transaction->customer_name,
                'note' => $data['note'] ?? $transaction->note,
            ]);

            Log::create([
                'user_id' => $user->id,
                'activity' => 'Ubah data transaksi',
                'detail' => $user->name . ' memperbarui transaksi ' . $transaction->trx_no . ' (Pelanggan: ' . ($data['customer_name'] ?? '-') . ')',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Detail transaksi berhasil diperbarui.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cancel(Request $request, $id)
    {
        $user = Auth::user();

        $transaction = Transaction::with('items')->findOrFail($id);

        if ($user->role === 'cashier') {
            $transactionDate = Carbon::parse($transaction->transaction_date)
                ->setTimezone(config('app.timezone'))
                ->startOfDay();
            $today = now()->setTimezone(config('app.timezone'))->startOfDay();

            if (!$transactionDate->equalTo($today)) {
                return response()->json([
                    'message' => 'Pembatalan hanya dapat dilakukan pada hari transaksi.'
                ], 400);
            }
        }

        $data = $request->validate([
            'void_reason' => 'required|string|max:255',
        ],
        [
            'void_reason.required' => 'Alasan pembatalan harus diisi.',
            'void_reason.string' => 'Alasan pembatalan tidak valid.',
            'void_reason.max' => 'Alasan pembatalan mencapai batas karakter maksimal.',
        ]);

        DB::beginTransaction();

        try {
            if ($transaction->status === 'void') {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaksi ini sudah dibatalkan sebelumnya.'
                ], 400);
            }

            $transaction->status = 'void';

            $transaction->void_reason = $data['void_reason'];
            $transaction->save();

            foreach ($transaction->items as $item) {
                if ($item->batch_id) {

                    $batch = ProductBatch::lockForUpdate()->findOrFail($item->batch_id);
                    $batch->increment('stock', $item->qty);
                }
            }

            Log::create([
                'user_id' => $user->id,
                'activity' => 'Batalkan transaksi',
                'detail' => $user->name . ' membatalkan transaksi ' . $transaction->trx_no . ' dengan alasan: ' . $data['void_reason'],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaksi berhasil dibatalkan dan stok telah dikembalikan ke batch masing-masing.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan transaksi: ' . $e->getMessage()
            ], 500);
        }
    }
}
