<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Mencatat penghapusan ke sync_deletions agar aplikasi HP ikut menghapus data
 * di penyimpanan lokalnya saat sinkron katalog.
 */
trait RecordsSyncDeletion
{
    protected static function bootRecordsSyncDeletion(): void
    {
        $event = in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(static::class), true)
            ? 'forceDeleted'
            : 'deleted';

        static::registerModelEvent($event, function ($model): void {
            DB::table('sync_deletions')->insert([
                'table_name' => $model->getTable(),
                'record_id' => $model->getKey(),
                'deleted_at' => now(),
            ]);
        });
    }
}
