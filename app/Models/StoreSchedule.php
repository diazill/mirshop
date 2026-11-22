<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Jadwal mingguan toko. day_of_week: 1 = Senin … 7 = Minggu. Diatur di Pengaturan Toko. */
class StoreSchedule extends Model
{
    use HasFactory;

    protected $fillable = ['day_of_week', 'open_time', 'close_time', 'is_closed'];

    protected $casts = [
        'day_of_week' => 'integer',
        'is_closed' => 'boolean',
    ];
}
