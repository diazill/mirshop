<?php

namespace App\Http\Middleware;

use App\Support\PublicResponseCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header cache untuk endpoint publik tanpa login. Hanya untuk respons 200: error (404, 429, …)
 * tidak boleh disimpan perantara.
 */
class PublicCacheHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodCacheable() && $response->getStatusCode() === 200) {
            $response->headers->set(
                'Cache-Control',
                'public, max-age=' . PublicResponseCache::TTL_SECONDS . ', stale-while-revalidate=300',
            );
        }

        return $response;
    }
}
