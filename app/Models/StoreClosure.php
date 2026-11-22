<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Libur khusus toko pada tanggal tertentu (di luar jadwal mingguan). */
class StoreClosure extends Model
{
    protected $fillable = ['date', 'reason'];

    protected $casts = ['date' => 'date'];
}
