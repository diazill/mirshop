<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Support\ProductImageUrl;
use App\Support\PublicResponseCache;
use App\Support\StoreProfile;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Katalog publik', 'Untuk toko online (Next.js), tanpa login', weight: 1)]
class CatalogController extends Controller
{
    /**
     * Daftar kategori.
     *
     * Semua kategori, termasuk yang belum punya produk. `product_count` hanya menghitung produk aktif
     * langsung di kategori itu. Urut `sort_order` (diatur di dashboard), lalu nama. Tanpa paginasi.
     * Hasil di-cache 60 detik.
     *
     * @unauthenticated
     */
    public function categories(Request $request): JsonResponse
    {
        $data = PublicResponseCache::remember($request, [], fn (): array => Category::query()
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'parent_id' => $c->parent_id,
                'product_count' => $c->products_count,
                'sort_order' => $c->sort_order,
            ])
            ->all());

        return response()->json(['data' => $data]);
    }

    /**
     * Daftar merek.
     *
     * Semua merek, urut nama. `product_count` hanya produk aktif. `logo_url` null bila belum ada logo.
     * Tanpa paginasi. Hasil di-cache 60 detik.
     *
     * @unauthenticated
     */
    public function brands(Request $request): JsonResponse
    {
        $data = PublicResponseCache::remember($request, [], fn (): array => Brand::query()
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $b): array => [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'logo_url' => ProductImageUrl::for($b->logo),
                'product_count' => $b->products_count,
            ])
            ->all());

        return response()->json(['data' => $data]);
    }

    /**
     * Info toko untuk toko online.
     *
     * Alamat, kontak, WhatsApp (digit saja, untuk `https://wa.me/{whatsapp}`), jadwal mingguan
     * (`day` 1 = Senin … 7 = Minggu), libur khusus 60 hari ke depan, dan status buka saat ini menurut
     * zona waktu toko. Field yang belum diisi owner bernilai null; `schedule` kosong dan `status` null
     * bila jadwal belum diatur. Hasil di-cache 60 detik, jadi status buka bisa terlambat ±1 menit.
     *
     * @unauthenticated
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => PublicResponseCache::remember($request, [], fn (): array => StoreProfile::publicPayload())]);
    }
}
