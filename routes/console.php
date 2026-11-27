<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bersihkan data lama yang punya aturan simpan (mis. audit log > 2 tahun). Butuh cron schedule:run di server.
\Illuminate\Support\Facades\Schedule::command('model:prune')->dailyAt('03:00');
