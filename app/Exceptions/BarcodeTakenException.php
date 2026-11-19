<?php

namespace App\Exceptions;

use App\Models\ProductUnit;

/** Barcode sudah terpasang di satuan produk lain. details menunjuk pemiliknya. */
class BarcodeTakenException extends BusinessException
{
    public function __construct(public readonly string $barcode, public readonly ProductUnit $owner)
    {
        $owner->loadMissing(['product' => fn ($q) => $q->withTrashed(), 'unit']);

        parent::__construct("Barcode {$barcode} sudah dipakai {$owner->product?->title} ({$owner->unit?->name}).");
    }

    public function errorCode(): string
    {
        return 'BARCODE_SUDAH_DIPAKAI';
    }

    public function status(): int
    {
        return 409;
    }

    public function details(): array
    {
        return [
            'product_id' => $this->owner->product_id,
            'product_unit_id' => $this->owner->id,
            'title' => $this->owner->product?->title,
            'unit_name' => $this->owner->unit?->name,
        ];
    }
}
