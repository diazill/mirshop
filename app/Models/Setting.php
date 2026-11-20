<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pengaturan toko sederhana: satu baris per key. Baca/tulis lewat App\Support\StoreProfile. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];
}
