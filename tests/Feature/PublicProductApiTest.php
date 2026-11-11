<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_active_products_and_public_image_urls(): void
    {
        Storage::fake('s3');
        config([
            'filesystems.disks.s3.url' => 'https://images.example.test/storage',
            'filesystems.disks.s3.bucket' => 'catalog',
        ]);

        $category = Category::query()->create(['name' => 'Kucing']);
        $brand = Brand::query()->create(['name' => 'Felibite']);
        $product = $this->createProduct($category, $brand, [
            'title' => 'Felibite Adult Cat',
            'is_best_product' => true,
        ]);
        ProductImage::query()->create([
            'product_id' => $product->id,
            'image_url' => 'katalog-produk/felibite.webp',
            'is_primary' => true,
            'sort_order' => 1,
        ]);
        $this->createProduct($category, $brand, [
            'title' => 'Produk nonaktif',
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.title', 'Felibite Adult Cat')
            ->assertJsonPath('data.0.category.name', 'Kucing')
            ->assertJsonPath('data.0.brand.name', 'Felibite')
            ->assertJsonPath('data.0.stock_status', 'tersedia')
            ->assertJsonPath('data.0.images.0.is_primary', true)
            ->assertJsonPath('data.0.image', '/storage/katalog-produk/felibite.webp')
            ->assertJsonMissingPath('data.0.cost_price');
    }

    public function test_it_filters_products_by_search_and_category(): void
    {
        $cats = Category::query()->create(['name' => 'Kucing']);
        $birds = Category::query()->create(['name' => 'Burung']);
        $brand = Brand::query()->create(['name' => 'Merek']);

        $matching = $this->createProduct($cats, $brand, ['title' => 'Pakan Kucing']);
        $this->createProduct($birds, $brand, ['title' => 'Pakan Burung']);

        $this->getJson('/api/v1/products?q=kucing&category_id='.$cats->id)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $matching->id);
    }

    private function createProduct(Category $category, Brand $brand, array $attributes = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => null,
            'title' => 'Produk Contoh',
            'price' => 12000,
            'cost_price' => 8000,
            'weight_gram' => 500,
            'current_stock' => 4,
            'is_active' => true,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
        ], $attributes));
    }
}
