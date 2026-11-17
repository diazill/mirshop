<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/** Token login aplikasi HP. device_id terisi bila login dari perangkat terdaftar. */
class PersonalAccessToken extends SanctumToken
{
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
