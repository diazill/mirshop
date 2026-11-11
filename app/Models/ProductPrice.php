<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPrice extends Model
{
    use HasFactory;

    public const TYPES = [
        'eceran' => 'Eceran',
        'grosir' => 'Grosir',
        'langganan' => 'Langganan',
    ];

    protected $fillable = ['product_unit_id', 'price_type', 'min_qty', 'price'];

    protected $casts = [
        'min_qty' => 'decimal:3',
        'price' => 'integer',
    ];

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }
}
