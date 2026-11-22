<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Slug untuk URL toko online. Dibuat dari kolom sumber saat data dibuat dan TIDAK ikut berubah
 * saat nama diganti, supaya tautan yang sudah dibagikan tetap jalan. Slug bisa diubah manual;
 * slug kosong dibuat ulang otomatis. Slug yang hanya angka diberi awalan agar tidak tertukar dengan id.
 *
 * Model yang memakai trait ini mendefinisikan slugSource() dan slugFallback().
 */
trait HasSlug
{
    protected static function bootHasSlug(): void
    {
        static::saving(function ($model): void {
            // Hanya saat slug kosong atau diubah: simpan stok/harga tidak menambah query.
            if (filled($model->slug) && ! $model->isDirty('slug')) {
                return;
            }

            $model->slug = $model->makeUniqueSlug(
                filled($model->slug) ? (string) $model->slug : (string) $model->{$model->slugSource()},
            );
        });
    }

    public static function normalizeSlug(string $value, string $fallback): string
    {
        $slug = Str::slug($value) ?: $fallback;
        $slug = ctype_digit($slug) ? "{$fallback}-{$slug}" : $slug;

        return rtrim(Str::limit($slug, 180, ''), '-');
    }

    /** Bentuk baku slug untuk model ini (tanpa cek unik). */
    public function normalizedSlug(string $value): string
    {
        return static::normalizeSlug($value, $this->slugFallback());
    }

    public function makeUniqueSlug(string $value): string
    {
        $base = $this->normalizedSlug($value);
        $slug = $base;

        for ($n = 2; $this->slugTaken($slug); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /** Dipakai juga oleh validasi form admin. */
    public function slugTaken(string $slug): bool
    {
        $query = static::query()->where('slug', $slug)->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()));

        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withTrashed();
        }

        return $query->exists() || $this->slugReservedElsewhere($slug);
    }

    /** Slug lama yang masih dipakai sebagai alamat lama (produk). Bawaan: tidak ada. */
    protected function slugReservedElsewhere(string $slug): bool
    {
        return false;
    }

    abstract protected function slugSource(): string;

    abstract protected function slugFallback(): string;
}
