<?php

namespace App\Enums;

/** Alasan wajib untuk koreksi stok manual. */
enum AdjustmentReason: string
{
    case Shrinkage = 'susut';        // pakan curah tercecer / selisih timbang
    case Damaged = 'rusak';
    case Expired = 'kedaluwarsa';
    case Lost = 'hilang';
    case Miscount = 'salah_hitung';
    case InitialStock = 'stok_awal'; // input stok awal saat mulai memakai sistem
    case Other = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Shrinkage => 'Susut / tercecer',
            self::Damaged => 'Rusak',
            self::Expired => 'Kedaluwarsa',
            self::Lost => 'Hilang',
            self::Miscount => 'Salah hitung',
            self::InitialStock => 'Stok awal',
            self::Other => 'Lainnya',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $reason) => [$reason->value => $reason->label()])->all();
    }
}
