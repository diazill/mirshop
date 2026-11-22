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
        // Toko online (GET /store/public). Kosong = null di API; Next.js punya nilai cadangan.
        'store_landmark' => null,
        'store_street' => null,
        'store_city' => null,
        'store_province' => null,
        'store_postal_code' => null,
        'store_country' => 'ID',
        'store_maps_url' => null,
        'store_latitude' => null,
        'store_longitude' => null,
        'store_whatsapp' => null,
        'store_email' => null,
    ];

    public const TIMEZONES = [
        'Asia/Jakarta' => 'WIB (Asia/Jakarta)',
        'Asia/Makassar' => 'WITA (Asia/Makassar)',
        'Asia/Jayapura' => 'WIT (Asia/Jayapura)',
    ];

    /**
     * @param  bool  $nameFallback  true: nama kosong diganti APP_NAME (struk kasir butuh nama);
     *                              false: tetap null (toko online punya nilai cadangan sendiri)
     * @return array<string, string|null>
     */
    public static function all(bool $nameFallback = true): array
    {
        $stored = Setting::query()->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all();

        $values = array_merge(self::DEFAULTS, array_filter($stored, fn ($v) => $v !== null && $v !== ''));
        if ($nameFallback) {
            $values['store_name'] ??= config('app.name');
        }

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

    /** Info toko untuk toko online (GET /v1/store/public). Hanya data yang memang ditampilkan ke publik. */
    public static function publicPayload(): array
    {
        $v = self::all(nameFallback: false);
        $timezone = array_key_exists((string) $v['store_timezone'], self::TIMEZONES) ? $v['store_timezone'] : self::DEFAULTS['store_timezone'];
        $hours = new StoreHours($timezone);

        return [
            'name' => $v['store_name'],
            'address' => [
                'landmark' => $v['store_landmark'],
                'street' => $v['store_street'],
                'city' => $v['store_city'],
                'province' => $v['store_province'],
                'postal_code' => $v['store_postal_code'],
                'country' => $v['store_country'],
            ],
            'maps_url' => $v['store_maps_url'],
            'latitude' => is_numeric($v['store_latitude']) ? (float) $v['store_latitude'] : null,
            'longitude' => is_numeric($v['store_longitude']) ? (float) $v['store_longitude'] : null,
            'phone' => $v['store_phone'],
            'whatsapp' => self::whatsappDigits($v['store_whatsapp']),
            'email' => $v['store_email'],
            'logo_url' => ProductImageUrl::for($v['store_logo']),
            'timezone' => $timezone,
            'schedule' => $hours->schedule(),
            'closures' => $hours->upcomingClosures(),
            'status' => $hours->status(),
        ];
    }

    /** "0851-5542-8611" / "+62 851…" → "6285155428611" (untuk https://wa.me/…). */
    public static function whatsappDigits(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        return str_starts_with($digits, '0') ? '62' . substr($digits, 1) : $digits;
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
