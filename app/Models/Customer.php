<?php

namespace App\Models;

use App\Models\Concerns\RecordsSyncDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pelanggan toko. Dibuat dari kasir (POST /v1/customers) atau dari panel admin.
 * Nomor HP disimpan dalam bentuk baku (08xxx) agar pencarian dan cek ganda konsisten.
 *
 * price_type dan credit_limit disiapkan untuk harga langganan dan bon; belum dipakai.
 */
class Customer extends Model
{
    use RecordsSyncDeletion;
    use SoftDeletes;

    protected $fillable = ['name', 'phone', 'address', 'notes', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'credit_limit' => 'integer',
    ];

    protected $hidden = ['request_hash', 'idempotency_key'];

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = static::normalizePhone($value);
    }

    public function setNameAttribute(?string $value): void
    {
        $this->attributes['name'] = $value === null ? null : trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * "+62 812-3456-789", "62812...", "0812 3456 789" → "08123456789". Kosong → null.
     * Nomor non-Indonesia tetap disimpan sebagai angka saja.
     */
    public static function normalizePhone(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '62')) {
            $digits = '0' . substr($digits, 2);
        }

        return $digits;
    }

    public static function isValidPhone(?string $value): bool
    {
        if (preg_match('/[^0-9+\s().\-]/', (string) $value)) {
            return false;
        }

        return (bool) preg_match('/^\d{6,15}$/', (string) static::normalizePhone($value));
    }

    /** Pelanggan (aktif maupun nonaktif, belum dihapus) yang memakai nomor HP ini. */
    public static function findByPhone(?string $phone, ?int $ignoreId = null): ?self
    {
        $normalized = static::normalizePhone($phone);

        if ($normalized === null) {
            return null;
        }

        return static::query()
            ->where('phone', $normalized)
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Bentuk ringkas untuk API (cari, tambah cepat, sinkron, error PELANGGAN_SUDAH_ADA). */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'is_active' => $this->is_active && ! $this->trashed(),
        ];
    }
}
