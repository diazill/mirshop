<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/** URL publik foto produk: URL penuh dibiarkan, path disk dijadikan URL Supabase Storage. */
final class ProductImageUrl
{
    public static function for(?string $imagePath): ?string
    {
        if (blank($imagePath)) {
            return null;
        }

        if (str_starts_with($imagePath, 'https://') || str_starts_with($imagePath, 'http://')) {
            return $imagePath;
        }

        return Storage::disk('s3')->url($imagePath);
    }
}
