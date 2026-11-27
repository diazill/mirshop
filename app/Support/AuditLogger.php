<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Menulis audit log. Dipanggil langsung (login, pencabutan token) atau lewat trait
 * App\Models\Concerns\Auditable (perubahan data penting).
 *
 * Ditulis di transaksi DB yang sama dengan perubahannya: bila perubahan dibatalkan, catatannya ikut batal.
 * Tidak pernah menyimpan password atau token.
 */
final class AuditLogger
{
    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @param  array<string, mixed>  $context
     */
    public static function record(
        string $action,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?string $subjectLabel = null,
        array $changes = [],
        array $context = [],
        ?User $actor = null,
    ): AuditLog {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $actor ??= auth()->user();

        return AuditLog::query()->create([
            'user_id' => $actor instanceof User ? $actor->getKey() : null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => is_numeric($subjectId) ? (int) $subjectId : null,
            'subject_label' => $subjectLabel === null ? null : Str::limit($subjectLabel, 197),
            'changes' => $changes === [] ? null : $changes,
            'context' => $context === [] ? null : $context,
            'channel' => match (true) {
                $request === null => 'sistem',
                $request->is('api/*') => 'api',
                default => 'panel',
            },
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 252) ?: null : null,
        ]);
    }

    /** @param  array<string, array{0: mixed, 1: mixed}>  $changes */
    public static function model(string $action, Model $model, array $changes = []): void
    {
        self::record(
            action: $action,
            subjectType: method_exists($model, 'auditSubjectType') ? $model->auditSubjectType() : Str::snake(class_basename($model)),
            subjectId: $model->getKey(),
            subjectLabel: method_exists($model, 'auditLabel') ? $model->auditLabel() : '#' . $model->getKey(),
            changes: $changes,
        );
    }

    /** Nilai yang aman dan mudah dibaca untuk disimpan di kolom JSON. */
    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            is_scalar($value), $value === null => $value,
            default => json_encode($value),
        };
    }
}
