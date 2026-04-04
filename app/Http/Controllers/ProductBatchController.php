<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\ProductBatch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductBatchController extends Controller
{
    public function update(Request $request, $id)
    {
        $batch = ProductBatch::with('product')->findOrFail($id);

        $data = $request->validate([
            'batch_number' => 'required|string|max:255',
            'exp_date' => 'nullable|date|after_or_equal:today',
            'buy_price' => 'nullable|numeric|min:0',
        ],
        [
            'batch_number.required' => 'Nomor batch harus diisi',
            'batch_number.string' => 'Penamaan nomor batch tidak valid',
            'exp_date.after_or_equal' => 'Tanggal kadaluarsa tidak boleh di masa lalu',
            'buy_price.min' => 'Harga beli harus bernilai diatas angka nol',
            'buy_price.numeric' => 'Harga beli harus berupa angka',
        ]);

        $user = Auth::user();

        $old = $batch->batch_number;
        $changes = [];

        if (isset($data['batch_number']) && $data['batch_number'] !== $batch->batch_number) {
            $changes[] = "nomor batch: {$old} → {$data['batch_number']}";
        }
        if (isset($data['exp_date']) && $data['exp_date'] !== $batch->exp_date) {
            $changes[] = "exp date: {$batch->exp_date} → {$data['exp_date']}";
        }
        if (isset($data['buy_price']) && $data['buy_price'] != $batch->buy_price) {
            $changes[] = "harga beli: {$batch->buy_price} → {$data['buy_price']}";
        }

        DB::transaction(function () use ($data, $user, $batch, $changes) {
            $batch->update($data);

            if (!empty($changes)) {
                Log::create([
                    'user_id' => $user->id,
                    'activity' => 'Ubah data batch',
                    'detail' => "{$user->name} memperbarui batch {$batch->batch_number} pada produk {$batch->product->product_name}: " . implode(', ', $changes),
                ]);
            }
        });

        return response()->json(['success' => true]);
    }

    public function getBestBatch($productId, Request $request)
    {
        $qty = $request->query('qty', 1);

        $batch = ProductBatch::where('product_id', $productId)
            ->where('stock', '>=', $qty)
            ->where('exp_date', '>=', now()->startOfDay())
            ->whereNull('deleted_at')
            ->orderBy('exp_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$batch) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada batch valid dengan stok mencukupi.',
                'data' => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'exp_date' => Carbon::parse($batch->exp_date)?->toIso8601String(),
                'available_stock' => $batch->stock,
                'buy_price' => $batch->buy_price, // Opsional: untuk kalkulasi margin
            ]
        ]);
    }

    public function destroy($id)
    {
        $user = Auth::user();
        $batch = ProductBatch::with('product')->findOrFail($id);

        if ($batch->transactionItems()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Batch tidak dapat dihapus karena sudah memiliki riwayat transaksi.'
            ], 400);
        }

        $batchNumber = $batch->batch_number;
        $productName = $batch->product->product_name;

        DB::transaction(function () use ($id, $user, $batch, $batchNumber, $productName) {
            $batch->delete();

            Log::create([
                'user_id' => $user->id,
                'activity' => 'Hapus batch',
                'detail' => $user->name . ' telah menghapus batch ' . $batchNumber . ' pada produk ' . $productName . ' (ID: ' . $id . ')',
            ]);
        });

        return response()->json(['success' => true]);
    }
}
