<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'unit_id',
        'product_name',
        'barcode',
        'sell_price',
        'description',
        'min_stock',
        'image_path',
        'image_url',
    ];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image_path) return null;

        $baseUrl = rtrim(env('SUPABASE_PUBLIC_URL'), '/');
        $bucket = env('SUPABASE_BUCKET');

        return "{$baseUrl}/{$bucket}/{$this->image_path}";
    }

    protected static function booted() {
        static::deleting(function ($product) {

        $product->batches()->each(fn($batch) => $batch->delete());
        $product->productCategories()->each(fn($pc) => $pc->delete());

        });
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class, 'product_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories', 'product_id', 'category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class, 'product_id');
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class, 'product_id');
    }
}
