<?php

namespace App\Models\Concerns;

use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catat perubahan kolom penting ke audit log. Model mendefinisikan auditedFields(), dan boleh
 * mendefinisikan auditLabel(), auditSubjectType(), auditEvents() serta maskedAuditFields().
 *
 * Simpan yang tidak mengubah kolom yang diawasi (mis. stok berubah karena penjualan) tidak menulis apa pun
 * dan tidak menambah query.
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($model): void {
            if (in_array('created', $model->auditEvents(), true)) {
                AuditLogger::model('created', $model, $model->auditChanges(created: true));
            }
        });

        static::updated(function ($model): void {
            if (in_array('updated', $model->auditEvents(), true) && ($changes = $model->auditChanges()) !== []) {
                AuditLogger::model('updated', $model, $changes);
            }
        });

        static::deleted(function ($model): void {
            if (in_array('deleted', $model->auditEvents(), true)) {
                AuditLogger::model('deleted', $model);
            }
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function ($model): void {
                if (in_array('deleted', $model->auditEvents(), true)) {
                    AuditLogger::model('restored', $model);
                }
            });
        }
    }

    /** @return array<int, string> */
    abstract public function auditedFields(): array;

    /** @return array<int, string> created | updated | deleted (deleted juga mencakup restored) */
    public function auditEvents(): array
    {
        return ['created', 'updated', 'deleted'];
    }

    /** Kolom yang isinya tidak boleh disimpan, hanya fakta bahwa ia berubah (mis. password). */
    public function maskedAuditFields(): array
    {
        return [];
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    public function auditChanges(bool $created = false): array
    {
        $changes = [];

        foreach ($this->auditedFields() as $field) {
            if ($created) {
                $new = $this->getAttribute($field);

                if ($new !== null && ! in_array($field, $this->maskedAuditFields(), true)) {
                    $changes[$field] = [null, AuditLogger::normalize($new)];
                }

                continue;
            }

            if (! $this->wasChanged($field)) {
                continue;
            }

            $changes[$field] = in_array($field, $this->maskedAuditFields(), true)
                ? ['•••', '••• (diubah)']
                : [AuditLogger::normalize($this->getOriginal($field)), AuditLogger::normalize($this->getAttribute($field))];
        }

        return $changes;
    }
}
