<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Audit log: login staf dan perubahan data penting. Hanya owner, hanya baca. */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = 'Pengaturan';
    protected static ?string $modelLabel = 'Audit log';
    protected static ?string $pluralModelLabel = 'Audit log';
    protected static ?string $slug = 'audit-log';
    protected static ?int $navigationSort = 90;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasRole('owner');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user:id,name,username'))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Oleh')
                    ->placeholder('Tamu / sistem')
                    ->searchable(),
                Tables\Columns\TextColumn::make('action')
                    ->label('Aksi')
                    ->badge()
                    ->formatStateUsing(fn (AuditLog $record): string => $record->actionLabel())
                    ->color(fn (string $state): string => match ($state) {
                        'login_failed', 'login_blocked', 'deleted', 'token_revoked' => 'danger',
                        'login_success' => 'success',
                        'updated' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('subject_label')
                    ->label('Data')
                    ->description(fn (AuditLog $record): ?string => $record->subjectTypeLabel())
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('summary')
                    ->label('Perubahan / keterangan')
                    ->state(fn (AuditLog $record): ?string => static::summary($record))
                    ->limit(80)
                    ->wrap(),
                Tables\Columns\TextColumn::make('channel')->label('Dari')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('ip_address')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')->label('Aksi')->options(AuditLog::ACTIONS),
                Tables\Filters\SelectFilter::make('subject_type')->label('Jenis data')->options(AuditLog::SUBJECTS),
                Tables\Filters\SelectFilter::make('user_id')->label('Oleh')->relationship('user', 'name')->searchable()->preload(),
                Tables\Filters\SelectFilter::make('channel')->label('Dari')->options(['panel' => 'Panel admin', 'api' => 'Aplikasi HP / API', 'sistem' => 'Sistem']),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(3)->schema([
                Infolists\Components\TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                Infolists\Components\TextEntry::make('user.name')->label('Oleh')->placeholder('Tamu / sistem'),
                Infolists\Components\TextEntry::make('action')->label('Aksi')->formatStateUsing(fn (AuditLog $record): string => $record->actionLabel()),
                Infolists\Components\TextEntry::make('subject_label')
                    ->label('Data')
                    ->helperText(fn (AuditLog $record): ?string => $record->subjectTypeLabel()),
                Infolists\Components\TextEntry::make('channel')->label('Dari'),
                Infolists\Components\TextEntry::make('ip_address')->label('IP')->placeholder('-'),
                Infolists\Components\TextEntry::make('user_agent')->label('Perangkat/browser')->placeholder('-')->columnSpanFull(),
            ]),
            Infolists\Components\KeyValueEntry::make('changes_view')
                ->label('Perubahan (lama → baru)')
                ->state(fn (AuditLog $record): array => collect($record->changes ?? [])
                    ->map(fn (array $pair): string => static::display($pair[0] ?? null) . '  →  ' . static::display($pair[1] ?? null))
                    ->all())
                ->visible(fn (AuditLog $record): bool => ! empty($record->changes))
                ->columnSpanFull(),
            Infolists\Components\KeyValueEntry::make('context_view')
                ->label('Keterangan')
                ->state(fn (AuditLog $record): array => collect($record->context ?? [])->map(fn ($v): string => static::display($v))->all())
                ->visible(fn (AuditLog $record): bool => ! empty($record->context))
                ->columnSpanFull(),
        ]);
    }

    public static function summary(AuditLog $record): ?string
    {
        if (! empty($record->changes)) {
            return collect($record->changes)
                ->map(fn (array $pair, string $field): string => "{$field}: " . static::display($pair[0] ?? null) . ' → ' . static::display($pair[1] ?? null))
                ->implode('; ');
        }

        if (! empty($record->context)) {
            return collect($record->context)->map(fn ($v, string $k): string => "{$k}: " . static::display($v))->implode('; ');
        }

        return null;
    }

    private static function display(mixed $value): string
    {
        return match (true) {
            $value === null => '-',
            $value === true => 'ya',
            $value === false => 'tidak',
            default => (string) $value,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
