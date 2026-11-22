<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Slug lama produk. GET /products/{slug} tetap menemukan produknya setelah slug diganti. */
class ProductSlug extends Model
{
    protected $fillable = ['product_id', 'slug'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
