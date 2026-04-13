<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Log;
use App\Models\Product;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Product::with(['categories', 'unit', 'batches']);

            if ($request->has('category_ids')) {
                $catIds = is_array($request->category_ids) ? $request->category_ids : [$request->category_ids];

                $query->whereHas('categories', function ($q) use ($catIds) {
                    $q->whereIn('categories.id', $catIds);
                });
            }

            $sort = $request->query('sort', 'newest');
            switch ($sort) {
                case 'az':
                    $query->orderBy('product_name', 'asc');
                    break;
                case 'za':
                    $query->orderBy('product_name', 'desc');
                    break;
                case 'oldest':
                    $query->orderBy('created_at', 'asc');
                    break;
                default:
                    
                    $query->orderBy('created_at', 'desc');
                    break;
            }

            if ($request->filled('search')) {
                $search = strtolower(trim($request->search));

                $query->where(function($q) use ($search) {
                    $q->where('barcode', $search)
                        ->orWhereRaw('LOWER(product_name) LIKE ?', ["%{$search}%"]);
                });
            }

            $products = $query->paginate(12);

            return response()->json(
                [
                    'success' => true,
                    'data' => $products,
                ],
                200,
            );
        } catch (\Exception $e) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                ],
                500,
            );
        }
    }

    public function validateCart(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer',
        ]);

        
        $validIds = Product::whereIn('id', $request->product_ids)
            ->pluck('id')
            ->toArray();

        return response()->json([
            'success'   => true,
            'valid_ids' => $validIds,
        ]);
    }

    public function getStockAlerts(Request $request)
    {
        try {
            $filtersString = $request->query('filters', '');
            $filters = empty($filtersString) ? [] : explode(',', $filtersString);
            $search = $request->query('search');
            $tz = config('app.timezone', 'Asia/Jakarta');

            
            if (empty($filters)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ], 200);
            }

            
            $query = Product::with(['categories', 'unit', 'batches' => fn($q) => $q->whereNull('deleted_at')])
                ->whereNull('products.deleted_at');

            
            if ($search) {
                $search = strtolower(trim($search));
                $query->where(function($q) use ($search) {
                    $q->where('barcode', $search)
                    ->orWhereRaw('LOWER(product_name) LIKE ?', ["%{$search}%"]);
                });
            }

            
            $products = $query->get()->filter(function($product) use ($filters, $tz) {
                $totalStock = $product->batches->whereNull('deleted_at')->sum('stock');

                foreach ($filters as $filter) {
                    $filter = trim($filter);

                    switch ($filter) {
                        case 'low_stock':
                            if ($totalStock <= $product->min_stock && $totalStock > 0) return true;
                            break;
                        case 'near_expiry':
                            $hasNearExpiry = $product->batches->contains(fn($b) =>
                                $b->exp_date &&
                                Carbon::parse($b->exp_date)->setTimezone($tz)->startOfDay() >= Carbon::now()->setTimezone($tz)->startOfDay() &&
                                Carbon::parse($b->exp_date)->setTimezone($tz)->endOfDay() <= Carbon::now()->setTimezone($tz)->addDays(30)->endOfDay() &&
                                $b->stock > 0
                            );
                            if ($hasNearExpiry) return true;
                            break;
                        case 'out_of_stock':
                            if ($totalStock <= 0) return true;
                            break;
                        case 'expired':
                            $hasExpired = $product->batches->contains(fn($b) =>
                                $b->exp_date &&
                                Carbon::parse($b->exp_date)->setTimezone($tz)->startOfDay() < Carbon::now()->setTimezone($tz)->startOfDay() &&
                                $b->stock > 0
                            );
                            if ($hasExpired) return true;
                            break;
                    }
                }
                return false;
            });

            
            return response()->json([
                'success' => true,
                'data' => $products->values(), 
            ], 200);

        } catch (\Exception $e) {
            
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function indexCategory()
    {
        $categories = Cache::remember('categories_all', 3600, fn() => Category::all());

        return response()->json(
            [
                'status' => true,
                'message' => 'GET data sukses',
                'data' => ['categories' => $categories],
            ],
            200,
        );
    }

    public function indexUnit()
    {
        $units = Cache::remember('units_all', 3600, fn() => Unit::all());

        return response()->json(
            [
                'status' => true,
                'message' => 'GET data sukses',
                'data' => ['units' => $units],
            ],
            200,
        );
    }

    public function store(Request $request)
    {
        $cleanName = ucwords(strtolower(trim($request->product_name)));
        $request->merge(['product_name' => $cleanName]);

            $data = $request->validate(
            [
                'product_name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('products', 'product_name')->whereNull('deleted_at'),
                ],
                'barcode' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('products', 'barcode')->whereNull('deleted_at'),
                ],
                'category_ids' => 'required|array',
                'category_ids.*' => 'exists:categories,id',
                'unit_id' => 'required|exists:units,id',
                'sell_price' => [
                    'required',
                    'numeric',
                    'min:0',
                    function ($attribute, $value, $fail) use ($request) {
                        $buyPrice = data_get($request->all(), 'batch.buy_price', 0);

                        if ($value < $buyPrice) {
                            $fail('Harga jual tidak boleh di bawah harga beli.');
                        }
                    },
                ],
                'min_stock' => 'nullable|integer|min:0',
                'description' => 'nullable|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',

                'batch.buy_price' => 'required|numeric|min:0',
                'batch.stock' => 'required|integer|min:1',
                'batch.batch_number' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
                'batch.exp_date' => 'nullable|date|after:today',
            ],
            [
                'product_name.required' => 'Nama produk tidak boleh kosong.',
                'product_name.unique' => 'Nama produk sudah terdaftar untuk produk aktif.',
                'barcode.unique' => 'Barcode ini sudah terdaftar untuk produk lain.',
                'sell_price.required' => 'Harga jual wajib diisi.',
                'category_ids.required' => 'Silakan pilih kategori produk.',
                'category_ids.*.exists' => 'Kategori yang dipilih sudah dihapus dari database.',
                'unit_id.required' => 'Silakan pilih satuan produk.',
                'batch.buy_price.required' => 'Harga beli wajib diisi.',
                'batch.stock.min' => 'Stok awal minimal adalah 1.',
                'batch.exp_date.after' => 'Tanggal kadaluarsa harus lebih dari hari ini.',
                'image.max' => 'Ukuran foto maksimal adalah 2MB.',
            ],
        );

        if (!empty($data['barcode'])) {
            $trashedBarcode = Product::onlyTrashed()
                ->where('barcode', $data['barcode'])
                ->exists();

            if ($trashedBarcode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Barcode ini sudah digunakan untuk produk yang diarsipkan.',
                    'errors' => ['barcode' => ['Barcode sudah terdaftar (produk diarsipkan)']]
                ], 422);
            }
        }

        $imagePath = null;
        $imageUrl = null;
        $disk = 'supabase';

        if ($request->hasFile('image')) {
            try {
                $imagePath = $request->file('image')->store('products', $disk);

                $bucket = env('SUPABASE_BUCKET');
                $baseUrl = rtrim(env('SUPABASE_PUBLIC_URL'), '/');

                $imageUrl = $baseUrl . '/' . $bucket . '/' . $imagePath;
            } catch (\Exception $e) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => 'Gagal mengunggah gambar ke Supabase: ' . $e->getMessage(),
                    ],
                    500,
                );
            }
        }

        $user = Auth::user();

        DB::beginTransaction();

        try {
            $product = Product::create([
                'product_name' => $data['product_name'],
                'barcode' => $data['barcode'] ?? null,
                'unit_id' => $data['unit_id'],
                'sell_price' => $data['sell_price'],
                'min_stock' => $data['min_stock'] ?? 0,
                'description' => $data['description'] ?? null,
                'image_url' => $imageUrl,
                'image_path'   => $imagePath,
            ]);

            $product->categories()->sync($request->category_ids);

            $product->batches()->create([
                'batch_number' => $data['batch']['batch_number'] ?? null,
                'exp_date' => $data['batch']['exp_date'] ?? null,
                'buy_price' => $data['batch']['buy_price'],
                'stock' => $data['batch']['stock'],
            ]);

            Log::create([
                'user_id' => $user->id,
                'activity' => 'Tambah data produk',
                'detail' => $user->name . ' menambahkan produk ' . $product->product_name . ' dengan stok awal ' . $data['batch']['stock'],
            ]);

            DB::commit();

            return response()->json(
                [
                    'success' => true,
                    'message' => 'Produk berhasil ditambahkan',
                    'data' => $product->load(['batches', 'categories']),
                ],
                201,
            );
        } catch (\Exception $e) {
            DB::rollBack();

            if ($imagePath) {
                Storage::disk($disk)->delete($imagePath);
            }

            return response()->json(
                [
                    'success' => false,
                    'message' => 'Gagal menyimpan data produk: ' . $e->getMessage(),
                ],
                500,
            );
        }
    }

    public function checkName(Request $request)
    {
        $name = trim($request->query('name', ''));
        $excludeId = $request->query('exclude_id');

        if (empty($name)) {
            return response()->json([
                'active' => false,
                'trashed' => false,
                'trashed_info' => null,
                'trashed_count' => 0,
            ]);
        }

        $activeQuery = Product::where('product_name', $name)->whereNull('deleted_at');
        if ($excludeId) {
            $activeQuery->where('id', '!=', $excludeId);
        }
        $isActive = $activeQuery->exists();

        $trashedQuery = Product::onlyTrashed()->where('product_name', $name);
        if ($excludeId) {
            $trashedQuery->where('id', '!=', $excludeId);
        }

        $trashedProducts = $trashedQuery
            ->orderBy('deleted_at', 'desc')
            ->get(['id', 'deleted_at', 'barcode', 'created_at']);

        $isTrashed = $trashedProducts->isNotEmpty();
        $trashedCount = $trashedProducts->count();


        $firstTrashed = $trashedProducts->first();
        $trashedInfo = $firstTrashed ? [
            'id' => $firstTrashed->id,
            'deleted_at' => $firstTrashed->deleted_at?->format('d M Y'),
            'barcode' => $firstTrashed->barcode ?? 'Tidak ada barcode',
        ] : null;

        return response()->json([
            'active' => $isActive,
            'trashed' => $isTrashed,
            'trashed_info' => $trashedInfo,
            'trashed_count' => $trashedCount,
            'trashed_products' => $trashedProducts->map(fn($p) => [
                'id' => $p->id,
                'deleted_at' => $p->deleted_at?->format('d M Y H:i'),
                'barcode' => $p->barcode ?? 'Tidak ada barcode',
            ])->toArray()
        ]);
    }

    public function show($id)
    {
        try {
            $product = Product::with(['categories', 'unit', 'batches'])->find($id);

            if (!$product) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => 'Produk tidak ditemukan',
                    ],
                    404,
                );
            }

            return response()->json(
                [
                    'success' => true,
                    'data' => $product,
                ],
                200,
            );
        } catch (\Exception $e) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                ],
                500,
            );
        }
    }

    public function update(Request $request, $id)
    {
        $cleanName = ucwords(strtolower(trim($request->product_name)));
        $request->merge(['product_name' => $cleanName]);

        $product = Product::with('categories', 'batches')->findOrFail($id);

        $data = $request->validate(
            [
                'product_name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('products', 'product_name')
                        ->whereNull('deleted_at')
                        ->ignore($id),
                ],
                'barcode' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('products', 'barcode')
                        ->whereNull('deleted_at')
                        ->ignore($id),
                ],
                'category_ids' => 'required|array',
                'category_ids.*' => 'exists:categories,id',
                'unit_id' => 'required|exists:units,id',
                'sell_price' => [
                    'required',
                    'numeric',
                    'min:0',
                    function ($attribute, $value, $fail) use ($request, $product) {
                        $minBuyPrice = $product->batches()->whereNull('deleted_at')->min('buy_price') ?? 0;
                        if ($value < $minBuyPrice) {
                            $fail('Harga jual tidak boleh di bawah harga beli.');
                        }
                    },
                ],
                'min_stock' => 'nullable|integer|min:0',
                'description' => 'nullable|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',

                'batch_id' => 'nullable|exists:product_batches,id',

                'new_batch_number' => 'nullable|string|max:255',
                'buy_price' => 'nullable|required_with:batch_id,new_batch_number|numeric|min:0',
                'exp_date' => 'nullable|required_with:new_batch_number|date',
                'stock_qty' => 'nullable|required_with:new_batch_number|integer|min:1',

                'stock_action' => 'nullable|in:add,reduce',
            ],
            [
                'new_batch_number.required_with' => 'Nomor batch harus diisi.',
                'buy_price.required_with' => 'Harga beli wajib diisi.',
                'exp_date.required_with' => 'Tanggal kadaluarsa wajib diisi untuk batch baru.',
                'stock_qty.required_with' => 'Jumlah stok wajib diisi.',

                'product_name.required' => 'Nama produk wajib diisi.',
                'product_name.unique' => 'Nama produk sudah terdaftar.',
                'barcode.unique' => 'Barcode ini sudah terdaftar untuk produk lain.',
                'category_ids.required' => 'Silakan pilih minimal 1 kategori.',
                'category_ids.*.exists' => 'Kategori yang dipilih sudah dihapus dari database.',
                'unit_id.required' => 'Silakan pilih jenis satuan.',
                'sell_price.required' => 'Harga jual wajib diisi.',
                'image.max' => 'Ukuran foto maksimal adalah 2MB.',
                'stock_qty.min' => 'Jumlah penyesuaian stok minimal adalah 1.',
            ],
        );

        if (!empty($data['barcode'])) {
            $trashedBarcode = Product::onlyTrashed()
                ->where('barcode', $data['barcode'])
                ->exists();

            if ($trashedBarcode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Barcode ini sudah digunakan untuk produk yang diarsipkan.',
                    'errors' => ['barcode' => ['Barcode sudah terdaftar (produk diarsipkan)']]
                ], 422);
            }
        }

        $disk = 'supabase';
        $user = Auth::user();

        $oldName = $product->product_name;

        $changes = [];

        if ($data['product_name'] !== $product->product_name) {
            $changes[] = "nama produk: {$product->product_name} → {$data['product_name']}";
        }

        if ($data['sell_price'] != $product->sell_price) {
            $changes[] = "harga jual: {$product->sell_price} → {$data['sell_price']}";
        }

        if (array_key_exists('barcode', $data) && $data['barcode'] !== $product->barcode) {
            $changes[] = "barcode: {$product->barcode} → {$data['barcode']}";
        }

        if (array_key_exists('min_stock', $data) && $data['min_stock'] != $product->min_stock) {
            $changes[] = "min stok: {$product->min_stock} → {$data['min_stock']}";
        }

        if (array_key_exists('description', $data) && $data['description'] !== $product->description) {
            $changes[] = "deskripsi diperbarui";
        }

        if ($request->hasFile('image')) {
            $changes[] = "gambar produk diperbarui";
        }

        try {
            DB::beginTransaction();

            if ($request->hasFile('image')) {
                if ($product->image_path) {
                    Storage::disk($disk)->delete($product->image_path);
                }

                $newImagePath = $request->file('image')->store('products', $disk);
                $product->image_path = $newImagePath;
            }

            $product->update([
                'product_name' => $data['product_name'],
                'barcode' => $data['barcode'] ?? $product->barcode,
                'unit_id' => $data['unit_id'],
                'sell_price' => $data['sell_price'],
                'min_stock' => $data['min_stock'] ?? $product->min_stock,
                'description' => $data['description'] ?? $product->description,
            ]);

            
            $oldCategories = $product->categories->pluck('id')->toArray();
            $newCategories = $data['category_ids'];

            sort($oldCategories);
            sort($newCategories);

            if ($oldCategories !== $newCategories) {
                $product->categories()->sync($newCategories);
                $changes[] = "kategori produk diperbarui";
            }

            if (!empty($data['new_batch_number'])) {
                $product->batches()->create([
                    'batch_number' => $data['new_batch_number'],
                    'exp_date' => $data['exp_date'],
                    'buy_price' => $data['buy_price'],
                    'stock' => $data['stock_qty'],
                ]);

                $changes[] = "menambahkan batch baru ({$data['new_batch_number']}) dengan stok {$data['stock_qty']}";
            } elseif (!empty($data['batch_id'])) {
                $batch = $product->batches()
                    ->where('id', $data['batch_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$batch || $batch->product_id !== $product->id) {
                    throw new \Exception("Batch tidak valid untuk produk ini.");
                }

                if ($batch) {
                    if (!empty($data['buy_price']) && $data['buy_price'] != $batch->buy_price) {
                        $changes[] = "harga beli batch: {$batch->buy_price} → {$data['buy_price']}";
                    }

                    if (!empty($data['exp_date']) && $data['exp_date'] != $batch->exp_date) {
                        $changes[] = "exp date batch diperbarui";
                    }

                    $batch->update([
                        'exp_date' => $data['exp_date'] ?? $batch->exp_date,
                        'buy_price' => $data['buy_price'] ?? $batch->buy_price,
                    ]);

                    if (!empty($data['stock_action']) && !empty($data['stock_qty'])) {
                        $qty = (int) $data['stock_qty'];

                        if ($data['stock_action'] === 'add') {
                            $changes[] = "menambah stok batch +{$qty}";
                            $batch->increment('stock', $qty);
                        } elseif ($data['stock_action'] === 'reduce') {
                            if ($qty > $batch->stock) {
                                throw new \Exception("Gagal: Jumlah pengurangan ($qty) melebihi stok batch yang ada ({$batch->stock}).");
                            }
                            $changes[] = "mengurangi stok batch -{$qty}";
                            $batch->decrement('stock', $qty);
                        }
                    }
                }
            }

            if (!empty($changes)) {
                Log::create([
                    'user_id' => $user->id,
                    'activity' => 'Ubah data produk',
                    'detail' => $user->name . ' memperbarui produk ' . $oldName . ' (ID: ' . $id . ') (' . implode(', ', $changes) . ')',
                ]);
            }

            DB::commit();

            return response()->json(
                [
                    'success' => true,
                    'message' => 'Produk berhasil diperbarui!',
                ],
                200,
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(
                [
                    'success' => false,
                    'message' => 'Gagal memperbarui produk: ' . $e->getMessage(),
                ],
                500,
            );
        }
    }

    public function restore($id)
    {
        $product = Product::withTrashed()->findOrFail($id);

        $conflict = Product::whereNull('deleted_at')
            ->where('id', '!=', $id)
            ->where(function($q) use ($product) {
                $q->where('product_name', $product->product_name)
                ->orWhere('barcode', $product->barcode);
            })->first();

        if ($conflict) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat restore. Nama/barcode sudah digunakan produk aktif.',
            ], 422);
        }

        $product->restore();

        Log::create([
            'user_id' => Auth::id(),
            'activity' => 'Restore produk',
            'detail' => Auth::user()->name . ' merestore produk ' . $product->product_name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil direstore.',
        ]);
    }

    public function destroy($id)
    {
        $user = Auth::user();

        DB::beginTransaction();

        try {
            $product = Product::findOrFail($id);

            if ($product->transactionItems()->exists()) {
                $product->delete();
                $message = 'Produk diarsipkan (Soft Delete) karena sudah memiliki riwayat penjualan.';
            } else {
                if ($product->image_path) {
                    Storage::disk('supabase')->delete($product->image_path);
                }

                $product->forceDelete();
                $message = 'Produk dan gambar berhasil dihapus permanen.';
            }

            Log::create([
                'user_id' => $user->id,
                'activity' => 'Hapus data produk',
                'detail' => $user->name . ' menghapus produk ' . $product->product_name . ' (ID: ' . $id . ')' . ' (' . $message . ')',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $message,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus produk: ' . $e->getMessage(),
            ], 500);
        }
    }
}
