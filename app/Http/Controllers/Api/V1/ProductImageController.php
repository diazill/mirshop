<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\UploadFailedException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ProductImageUrl;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Group('Gudang', 'Kelola barang dari aplikasi HP: barcode, stok, foto. Online.', weight: 4)]
class ProductImageController extends Controller
{
    /** Folder sama dengan upload dari admin (Filament). */
    private const DIRECTORY = 'katalog-produk';

    /**
     * Upload foto produk.
     *
     * multipart/form-data. Foto pertama otomatis jadi foto utama. `is_primary=true` menjadikan foto ini
     * utama dan melepas status utama foto lain. Disimpan ke Supabase Storage di folder yang sama dengan admin.
     * Peran: owner, admin, gudang. Dibatasi 20 upload per menit.
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            /** jpg, jpeg, png, atau webp, maks 5 MB. */
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_primary' => ['nullable', 'boolean'],
        ], [
            'image.required' => 'Foto wajib dikirim.',
            'image.mimes' => 'Foto harus jpg, png, atau webp.',
            'image.max' => 'Ukuran foto maksimal 5 MB.',
        ]);

        $file = $request->file('image');
        $directory = self::DIRECTORY . '/' . now()->format('Y/m');
        $name = Str::uuid() . '.' . strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());

        $path = Storage::disk('s3')->putFileAs($directory, $file, $name);
        if (! $path) {
            throw new UploadFailedException();
        }

        try {
            $image = $this->attach($product, $path, $data);
        } catch (\Throwable $exception) {
            // Jangan tinggalkan file yatim di Storage bila pencatatan ke database gagal.
            Storage::disk('s3')->delete($path);

            throw $exception;
        }

        $primary = $product->images()->orderByDesc('is_primary')->orderBy('sort_order')->first();

        return response()->json([
            'data' => [
                'id' => $image->id,
                'url' => ProductImageUrl::for($image->image_url),
                'is_primary' => $image->is_primary,
                'sort_order' => $image->sort_order,
            ],
            'product' => [
                'id' => $product->id,
                'image_url' => ProductImageUrl::for($primary?->image_url),
                'image_count' => $product->images()->count(),
            ],
        ], 201);
    }

    private function attach(Product $product, string $path, array $data): ProductImage
    {
        return DB::transaction(function () use ($product, $path, $data) {
            $hasImages = $product->images()->exists();
            $isPrimary = array_key_exists('is_primary', $data) && $data['is_primary'] !== null
                ? (bool) $data['is_primary']
                : ! $hasImages;

            if ($isPrimary) {
                $product->images()->where('is_primary', true)->update(['is_primary' => false]);
            }

            return $product->images()->create([
                'image_url' => $path,
                'is_primary' => $isPrimary,
                'sort_order' => ((int) $product->images()->max('sort_order')) + 1,
            ]);
        });
    }
}
