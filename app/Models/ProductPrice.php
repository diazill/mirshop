<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\RecordsSyncDeletion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPrice extends Model
{
    use Auditable, HasFactory, RecordsSyncDeletion;

    public function auditedFields(): array
    {
        return ['product_unit_id', 'price_type', 'min_qty', 'price'];
    }

    public function auditLabel(): string
    {
        $row = ProductUnit::query()
            ->join('products', 'products.id', '=', 'product_units.product_id')
            ->join('units', 'units.id', '=', 'product_units.unit_id')
            ->where('product_units.id', $this->product_unit_id)
            ->first(['products.title', 'units.name']);

        return ($row ? "{$row->title} ({$row->name})" : "Satuan #{$this->product_unit_id}") . " – harga {$this->price_type}";
    }

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
