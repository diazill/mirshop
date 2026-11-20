<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Info toko untuk kepala struk dan zona waktu toko.
 * Diatur owner di panel (Pengaturan Toko), dibaca kasir lewat GET /v1/store dan katalog sinkron.
 */
final class StoreProfile
{
    /** Key yang disimpan di tabel settings, beserta nilai bawaannya. */
    public const DEFAULTS = [
        'store_name' => null, // bawaan: APP_NAME
        'store_address' => null,
        'store_phone' => null,
        'receipt_footer' => 'Terima kasih',
        'store_logo' => null,
        'store_timezone' => 'Asia/Jakarta',
    ];

    public const TIMEZONES = [
        'Asia/Jakarta' => 'WIB (Asia/Jakarta)',
        'Asia/Makassar' => 'WITA (Asia/Makassar)',
        'Asia/Jayapura' => 'WIT (Asia/Jayapura)',
    ];

    /** @return array<string, string|null> */
    public static function all(): array
    {
        $stored = Setting::query()->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all();

        $values = array_merge(self::DEFAULTS, array_filter($stored, fn ($v) => $v !== null && $v !== ''));
        $values['store_name'] ??= config('app.name');

        return $values;
    }

    /** @param  array<string, string|null>  $values */
    public static function save(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => blank($value) ? null : trim((string) $value)]);
            }
        });
    }

    public static function timezone(): string
    {
        $timezone = (string) Setting::query()->whereKey('store_timezone')->value('value');

        return array_key_exists($timezone, self::TIMEZONES) ? $timezone : self::DEFAULTS['store_timezone'];
    }

    /** Bentuk untuk API: GET /v1/store dan field `store` di katalog sinkron. */
    public static function payload(): array
    {
        $values = self::all();

        return [
            'name' => $values['store_name'],
            'address' => $values['store_address'],
            'phone' => $values['store_phone'],
            'receipt_footer' => $values['receipt_footer'],
            'logo_url' => ProductImageUrl::for($values['store_logo']),
            'timezone' => array_key_exists((string) $values['store_timezone'], self::TIMEZONES)
                ? $values['store_timezone']
                : self::DEFAULTS['store_timezone'],
        ];
    }
}
