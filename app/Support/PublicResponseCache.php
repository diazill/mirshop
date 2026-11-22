<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Cache singkat untuk endpoint katalog publik (toko online). Datanya sama untuk semua pengunjung,
 * dan setiap query ke database memakan ±300 ms, jadi hasil disimpan sebentar.
 * Perubahan di dashboard terlihat di API paling lambat TTL detik kemudian.
 *
 * Paling efektif dengan CACHE_STORE=file (atau redis); dengan CACHE_STORE=database tetap ada 1 query.
 */
final class PublicResponseCache
{
    public const TTL_SECONDS = 60;

    /**
     * @template T
     *
     * @param  array<string, mixed>  $params  parameter yang sudah divalidasi
     * @param  callable(): T  $build
     * @return T
     */
    public static function remember(Request $request, array $params, callable $build): mixed
    {
        ksort($params);
        // Host ikut kunci: URL paginasi di respons memakai host permintaan.
        $key = 'public-api:' . sha1($request->getHost() . '|' . $request->path() . '|' . json_encode($params));

        return Cache::remember($key, self::TTL_SECONDS, $build);
    }
}
