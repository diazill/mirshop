<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'sort' => ['nullable', Rule::in(['featured', 'price_asc', 'price_desc', 'title'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->where('is_active', true)
            ->with([
                'category:id,name',
                'brand:id,name',
                'images' => fn ($query) => $query
                    ->select(['id', 'product_id', 'image_url', 'is_primary', 'sort_order'])
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order'),
            ])
            ->when(
                isset($filters['q']),
                function ($query) use ($filters): void {
                    $search = mb_strtolower($filters['q']);
                    $query->where(function ($query) use ($search): void {
                        $query
                            ->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(blurb) LIKE ?', ["%{$search}%"]);
                    });
                }
            )
            ->when(isset($filters['category_id']), fn ($query) => $query->where('category_id', $filters['category_id']))
            ->when(isset($filters['brand_id']), fn ($query) => $query->where('brand_id', $filters['brand_id']));

        match ($filters['sort'] ?? 'featured') {
            'price_asc' => $products->orderBy('price'),
            'price_desc' => $products->orderByDesc('price'),
            'title' => $products->orderBy('title'),
            default => $products->orderByDesc('is_best_product')->orderBy('title'),
        };

        $paginator = $products
            ->paginate($filters['per_page'] ?? 100)
            ->through(fn (Product $product): array => $this->publicProduct($product));

        return response()->json($paginator);
    }

    private function publicProduct(Product $product): array
    {
        $images = $product->images
            ->map(fn ($image): array => [
                'id' => $image->id,
                'url' => $this->publicImageUrl($image->image_url),
                'is_primary' => $image->is_primary,
                'sort_order' => $image->sort_order,
            ])
            ->values();

        $primaryImage = $images->firstWhere('is_primary', true) ?? $images->first();

        return [
            'id' => $product->id,
            'title' => $product->title,
            'blurb' => $product->blurb,
            'price' => $product->price,
            'weight_gram' => $product->weight_gram,
            'stock_status' => $product->current_stock > 0 ? 'tersedia' : 'habis',
            'is_best_product' => $product->is_best_product,
            'category' => $product->category
                ? ['id' => $product->category->id, 'name' => $product->category->name]
                : null,
            'brand' => $product->brand
                ? ['id' => $product->brand->id, 'name' => $product->brand->name]
                : null,
            'image' => $primaryImage['url'] ?? null,
            'images' => $images,
        ];
    }

    private function publicImageUrl(string $imagePath): string
    {
        if (str_starts_with($imagePath, 'https://') || str_starts_with($imagePath, 'http://')) {
            return $imagePath;
        }

        return Storage::disk('s3')->url($imagePath);
    }
}
