<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSlug;
use App\Support\ProductImageUrl;
use App\Support\PublicResponseCache;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Katalog publik', 'Untuk toko online (Next.js), tanpa login', weight: 1)]
class ProductController extends Controller
{
    /**
     * Daftar produk aktif.
     *
     * Untuk toko online. HPP dan jumlah stok tidak dikirim, hanya `stock_status` (tersedia/habis).
     * Filter kategori/merek bisa memakai id (`category_id`, `brand_id`) atau slug (`category`, `brand`);
     * slug yang tidak dikenal menghasilkan daftar kosong. `include_children=1` ikut menyertakan produk
     * di semua subkategori. `ids` / `slugs` (dipisah koma, maks. 100) mengambil produk tertentu sekaligus,
     * mis. untuk keranjang atau prerender; slug lama dan produk nonaktif ditangani seperti detail
     * produk (nonaktif tidak disertakan). Hasil di-cache 60 detik.
     * Dibatasi 120 permintaan per menit per IP (server toko online dengan `X-Storefront-Key`: lebih tinggi).
     *
     * @unauthenticated
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            /** Slug kategori, mis. `kucing`. */
            'category' => ['nullable', 'string', 'max:120'],
            /** Slug merek, mis. `royal-canin`. */
            'brand' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(['featured', 'price_asc', 'price_desc', 'title'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            /** Ikut sertakan produk di subkategori (untuk filter `category`/`category_id`). */
            'include_children' => ['nullable', 'boolean'],
            /** Id produk dipisah koma, maks. 100, mis. `8,12`. */
            'ids' => ['nullable', 'string', 'regex:/^\d+(,\d+)*$/', $this->maxItems()],
            /** Slug produk dipisah koma, maks. 100. Slug lama juga dikenali. */
            'slugs' => ['nullable', 'string', 'max:20000', 'regex:/^[a-z0-9-]+(,[a-z0-9-]+)*$/', $this->maxItems()],
        ]);

        $page = PublicResponseCache::remember($request, $filters, function () use ($filters): array {
            $products = $this->publicQuery()
                ->when(isset($filters['q']), function ($query) use ($filters): void {
                    $search = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'])) . '%';
                    $query->where(fn ($q) => $q
                        ->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$search])
                        ->orWhereRaw("LOWER(blurb) LIKE ? ESCAPE '!'", [$search]));
                })
                ->when(isset($filters['category_id']) || isset($filters['category']), fn ($query) => $query->whereIn(
                    'category_id',
                    $this->categoryIds($filters['category_id'] ?? null, $filters['category'] ?? null, (bool) ($filters['include_children'] ?? false)),
                ))
                ->when(isset($filters['brand_id']), fn ($query) => $query->where('brand_id', $filters['brand_id']))
                ->when(isset($filters['brand']), fn ($query) => $query->whereHas('brand', fn ($q) => $q->where('slug', $filters['brand'])))
                ->when(isset($filters['ids']), fn ($query) => $query->whereIn('id', array_map('intval', explode(',', $filters['ids']))))
                ->when(isset($filters['slugs']), function ($query) use ($filters): void {
                    $slugs = explode(',', $filters['slugs']);
                    $query->where(fn ($q) => $q
                        ->whereIn('slug', $slugs)
                        ->orWhereIn('id', ProductSlug::query()->whereIn('slug', $slugs)->select('product_id')));
                });

            match ($filters['sort'] ?? 'featured') {
                'price_asc' => $products->orderBy('price'),
                'price_desc' => $products->orderByDesc('price'),
                'title' => $products->orderBy('title'),
                default => $products->orderByDesc('is_best_product')->orderBy('title'),
            };

            return $products
                ->orderBy('id')
                ->paginate($filters['per_page'] ?? 100)
                ->through(fn (Product $product): array => $this->publicProduct($product))
                ->toArray();
        });

        return response()->json($page);
    }

    /**
     * Detail produk.
     *
     * `{key}` = slug produk. Selama masa transisi `id` angka juga diterima (tautan lama `/produk/8`),
     * begitu juga slug lama setelah slug diganti. Bila `data.slug` berbeda dari yang diminta,
     * arahkan pengunjung (redirect permanen) ke slug baru. Produk nonaktif atau tidak ada → 404
     * `TIDAK_DITEMUKAN`. Hasil di-cache 60 detik.
     *
     * @unauthenticated
     */
    public function show(Request $request, string $key): JsonResponse
    {
        abort_if(strlen($key) > 200, 404);

        $data = PublicResponseCache::remember($request, [], function () use ($key): ?array {
            $product = Product::findForStorefront($key);

            if (! $product || ! $product->is_active) {
                return null;
            }

            return $this->publicProduct($product->load($this->relations()));
        });

        abort_if($data === null, 404);

        return response()->json(['data' => $data]);
    }

    /** @return array<int, int> kategori yang diminta (+ semua turunannya bila diminta) */
    private function categoryIds(?int $id, ?string $slug, bool $withChildren): array
    {
        $all = Category::query()->get(['id', 'slug', 'parent_id']);
        $root = $id ?? $all->firstWhere('slug', $slug)?->id;

        if ($root === null) {
            return [];
        }

        $ids = [$root];

        for ($i = 0; $withChildren && $i < count($ids); $i++) {
            foreach ($all->where('parent_id', $ids[$i]) as $child) {
                if (! in_array($child->id, $ids, true)) {
                    $ids[] = $child->id;
                }
            }
        }

        return $ids;
    }

    private function maxItems(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (count(explode(',', (string) $value)) > 100) {
                $fail('Maksimal 100 item per permintaan.');
            }
        };
    }

    private function publicQuery()
    {
        return Product::query()->where('is_active', true)->with($this->relations());
    }

    private function relations(): array
    {
        return [
            'category:id,name,slug',
            'brand:id,name,slug,logo',
            'images' => fn ($query) => $query
                ->select(['id', 'product_id', 'image_url', 'is_primary', 'sort_order'])
                ->orderByDesc('is_primary')
                ->orderBy('sort_order'),
        ];
    }

    private function publicProduct(Product $product): array
    {
        $images = $product->images
            ->map(fn ($image): array => [
                'id' => $image->id,
                'url' => ProductImageUrl::for($image->image_url),
                'is_primary' => $image->is_primary,
                'sort_order' => $image->sort_order,
            ])
            ->values();

        $primaryImage = $images->firstWhere('is_primary', true) ?? $images->first();

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'title' => $product->title,
            'blurb' => $product->blurb,
            'price' => $product->price,
            'weight_gram' => $product->weight_gram,
            'stock_status' => $product->current_stock > 0 ? 'tersedia' : 'habis',
            'is_best_product' => $product->is_best_product,
            'category' => $product->category
                ? ['id' => $product->category->id, 'name' => $product->category->name, 'slug' => $product->category->slug]
                : null,
            'brand' => $product->brand
                ? [
                    'id' => $product->brand->id,
                    'name' => $product->brand->name,
                    'slug' => $product->brand->slug,
                    'logo_url' => ProductImageUrl::for($product->brand->logo),
                ]
                : null,
            'image' => $primaryImage['url'] ?? null,
            'images' => $images,
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }
}
