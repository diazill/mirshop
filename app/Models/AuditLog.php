<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Catatan audit. Ditulis lewat App\Support\AuditLogger; tidak bisa diubah atau dihapus dari
 * aplikasi. Dibersihkan otomatis setelah RETENTION_DAYS hari (php artisan model:prune).
 */
class AuditLog extends Model
{
    use MassPrunable;

    public const RETENTION_DAYS = 730;

    public const UPDATED_AT = null;

    public const ACTIONS = [
        'created' => 'Dibuat',
        'updated' => 'Diubah',
        'deleted' => 'Dihapus',
        'restored' => 'Dipulihkan',
        'login_success' => 'Login berhasil',
        'login_failed' => 'Login gagal',
        'login_blocked' => 'Login diblokir (terlalu sering)',
        'token_revoked' => 'Login aplikasi dicabut / logout',
    ];

    public const SUBJECTS = [
        'product' => 'Produk',
        'product_unit' => 'Satuan produk',
        'product_price' => 'Harga produk',
        'user' => 'Pengguna',
        'device' => 'Perangkat',
        'api_token' => 'Login aplikasi',
        'setting' => 'Pengaturan toko',
        'store_hours' => 'Jam buka & libur',
        'customer' => 'Pelanggan',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'changes' => 'array',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log tidak boleh diubah.'));
        static::deleting(fn () => throw new LogicException('Audit log tidak boleh dihapus.'));
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    public function subjectTypeLabel(): ?string
    {
        return $this->subject_type ? (self::SUBJECTS[$this->subject_type] ?? $this->subject_type) : null;
    }
}
