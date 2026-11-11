<?php

namespace App\Support;

/** Format rupiah tanpa ketergantungan ekstensi intl: 1250000 → "Rp 1.250.000". */
final class Money
{
    public static function rupiah(int|float|string|null $amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }
}
