<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** HP kasir/gudang. Kode (mis. K01) menjadi awalan nomor nota yang dibuat offline. */
class Device extends Model
{
    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function lastSyncedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_synced_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    /** User yang pernah login di perangkat ini: sah dicatat sebagai kasir nota offline. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'device_users')->withPivot('last_login_at');
    }

    public function hasUser(int $userId): bool
    {
        return $this->users()->whereKey($userId)->exists();
    }
}
