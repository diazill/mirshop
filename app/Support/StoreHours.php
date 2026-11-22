<?php

namespace App\Support;

use App\Models\StoreClosure;
use App\Models\StoreSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Jam buka toko: jadwal mingguan (store_schedules, day_of_week 1 = Senin … 7 = Minggu, ISO-8601)
 * ditambah libur khusus per tanggal (store_closures). Semua dihitung di zona waktu toko.
 */
final class StoreHours
{
    /** Libur khusus yang dikirim ke toko online: hari ini sampai sekian hari ke depan. */
    public const CLOSURE_DAYS_AHEAD = 60;

    /** @var Collection<int, StoreSchedule>|null */
    private ?Collection $days = null;

    /** @var Collection<string, StoreClosure>|null */
    private ?Collection $closures = null;

    public function __construct(private readonly string $timezone)
    {
    }

    /**
     * 7 baris Senin–Minggu, atau [] bila jadwal belum diatur owner.
     *
     * @return array<int, array{day: int, opens_at: ?string, closes_at: ?string, is_closed: bool}>
     */
    public function schedule(): array
    {
        $days = $this->days();

        if ($days->isEmpty()) {
            return [];
        }

        return collect(range(1, 7))->map(function (int $day) use ($days): array {
            $row = $days->get($day);
            $closed = ! $row || $row->is_closed;

            return [
                'day' => $day,
                'opens_at' => $closed ? null : substr((string) $row->open_time, 0, 5),
                'closes_at' => $closed ? null : substr((string) $row->close_time, 0, 5),
                'is_closed' => $closed,
            ];
        })->all();
    }

    /** @return array<int, array{date: string, reason: ?string}> */
    public function upcomingClosures(): array
    {
        return $this->closures()
            ->map(fn (StoreClosure $c): array => ['date' => $c->date->toDateString(), 'reason' => $c->reason])
            ->values()
            ->all();
    }

    /**
     * Buka sekarang? `closes_at` bila buka, `next_open_at` bila tutup. null bila jadwal belum diatur.
     *
     * @return array{is_open: bool, closes_at: ?string, next_open_at: ?string}|null
     */
    public function status(?CarbonImmutable $now = null): ?array
    {
        if ($this->days()->isEmpty()) {
            return null;
        }

        $now = ($now ?? CarbonImmutable::now())->setTimezone($this->timezone);
        [$opens, $closes] = $this->hoursOn($now) ?? [null, null];

        if ($opens && $now->greaterThanOrEqualTo($opens) && $now->lessThan($closes)) {
            return ['is_open' => true, 'closes_at' => $closes->toIso8601String(), 'next_open_at' => null];
        }

        return ['is_open' => false, 'closes_at' => null, 'next_open_at' => $this->nextOpening($now)?->toIso8601String()];
    }

    private function nextOpening(CarbonImmutable $now): ?CarbonImmutable
    {
        // Hari ini (bila belum buka) lalu 14 hari ke depan; cukup untuk libur panjang.
        for ($i = 0; $i <= 14; $i++) {
            $hours = $this->hoursOn($now->startOfDay()->addDays($i));

            if ($hours && $hours[0]->greaterThan($now)) {
                return $hours[0];
            }
        }

        return null;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null jam buka-tutup pada tanggal itu, null bila tutup */
    private function hoursOn(CarbonImmutable $date): ?array
    {
        $row = $this->days()->get($date->dayOfWeekIso);

        if (! $row || $row->is_closed || $this->closures()->has($date->toDateString())) {
            return null;
        }

        $opens = $date->setTimeFromTimeString((string) $row->open_time);
        $closes = $date->setTimeFromTimeString((string) $row->close_time);

        return $closes->greaterThan($opens) ? [$opens, $closes] : null;
    }

    /** @return Collection<int, StoreSchedule> */
    private function days(): Collection
    {
        return $this->days ??= StoreSchedule::query()
            ->whereBetween('day_of_week', [1, 7])
            ->orderBy('id')
            ->get()
            ->unique('day_of_week')
            ->keyBy('day_of_week');
    }

    /** @return Collection<string, StoreClosure> */
    private function closures(): Collection
    {
        $today = CarbonImmutable::now($this->timezone)->toDateString();

        return $this->closures ??= StoreClosure::query()
            ->whereDate('date', '>=', $today)
            ->whereDate('date', '<=', CarbonImmutable::parse($today)->addDays(self::CLOSURE_DAYS_AHEAD)->toDateString())
            ->orderBy('date')
            ->get()
            ->keyBy(fn (StoreClosure $c): string => $c->date->toDateString());
    }
}
