<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (Inventory $stock): void {
            if (! $stock->variant_id) {
                $variants = ProductVariant::where('product_id', $stock->product_id)->where('active', true)->get();
                if ($variants->count() !== 1) {
                    throw new \InvalidArgumentException('Select a variant for this inventory record.');
                }$stock->variant_id = $variants->first()->id;
            }
            if (! ProductVariant::whereKey($stock->variant_id)->where('product_id', $stock->product_id)->exists()) {
                throw new \InvalidArgumentException('The variant must belong to the inventory product.');
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
