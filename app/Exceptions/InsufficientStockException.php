<?php

namespace App\Exceptions;

use App\Models\Product;

class InsufficientStockException extends StockException
{
    public function __construct(
        public readonly Product $product,
        public readonly string $available,
        public readonly string $requested,
    ) {
        $unit = $product->baseUnit?->unit?->name ?? '';

        parent::__construct(trim(sprintf(
            'Stok %s tidak cukup. Tersedia %s %s, diminta %s %s.',
            $product->title,
            Product::formatQty($available), $unit,
            Product::formatQty($requested), $unit,
        )));
    }

    public function errorCode(): string
    {
        return 'STOK_TIDAK_CUKUP';
    }

    public function details(): array
    {
        return [
            'product_id' => $this->product->id,
            'available' => $this->available,
            'requested' => $this->requested,
        ];
    }
}
