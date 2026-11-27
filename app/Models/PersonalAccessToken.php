<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/** Token login aplikasi HP. device_id terisi bila login dari perangkat terdaftar. */
class PersonalAccessToken extends SanctumToken
{
    use Auditable;

    /** Hanya pencabutan/logout yang dicatat; last_used_at berubah di setiap request. */
    public function auditEvents(): array
    {
        return ['deleted'];
    }

    public function auditedFields(): array
    {
        return [];
    }

    public function auditSubjectType(): string
    {
        return 'api_token';
    }

    public function auditLabel(): string
    {
        $owner = $this->tokenable;

        return trim("{$this->name} – " . ($owner?->username ?: $owner?->name ?: '?'));
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
