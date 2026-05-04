<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class SlotAvailabilityService
{
    /**
     * Свободные слоты в пределах календарных дней [rangeStart, rangeEnd] (включительно), по порядку.
     *
     * @return list<array{start: Carbon, end: Carbon}>
     */
    public function suggestSlotsInDateRange(
        User $user,
        ?Service $service,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        int $maxSuggestions = 60,
    ): array {
        $duration = $service?->duration_minutes ?? 60;
        $working = WorkingHour::query()->where('user_id', $user->id)->get()->groupBy('weekday');
        $appointments = Appointment::query()
            ->where('user_id', $user->id)
            ->confirmed()
            ->where('ends_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')
            ->get();

        $from = $rangeStart->copy()->startOfDay();
        $to = $rangeEnd->copy()->startOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        $today = now()->startOfDay();
        if ($to->lt($today)) {
            return [];
        }
        if ($from->lt($today)) {
            $from = $today->copy();
        }

        $suggestions = [];
        $day = $from->copy();
        while ($day->lte($to) && count($suggestions) < $maxSuggestions) {
            $weekday = (int) $day->dayOfWeek;
            foreach ($working->get($weekday, collect()) as $wh) {
                foreach ($this->slotsForWorkingWindow($day, $wh, $duration, $appointments) as $slot) {
                    if (count($suggestions) >= $maxSuggestions) {
                        break 3;
                    }
                    $suggestions[] = $slot;
                }
            }
            $day->addDay();
        }

        return $suggestions;
    }

    /**
     * @return list<array{start: Carbon, end: Carbon}>
     */
    public function suggestSlots(User $user, ?Service $service, int $daysAhead = 14, int $maxSuggestions = 24): array
    {
        $duration = $service?->duration_minutes ?? 60;
        $working = WorkingHour::query()->where('user_id', $user->id)->get()->groupBy('weekday');
        $appointments = Appointment::query()
            ->where('user_id', $user->id)
            ->confirmed()
            ->where('ends_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')
            ->get();

        $suggestions = [];
        $period = CarbonPeriod::create(now()->startOfDay(), now()->addDays($daysAhead)->endOfDay());

        foreach ($period as $day) {
            if (count($suggestions) >= $maxSuggestions) {
                break;
            }
            $weekday = (int) $day->dayOfWeek;
            /** @var Collection<int, WorkingHour> $slots */
            $slots = $working->get($weekday, collect());
            foreach ($slots as $wh) {
                if (count($suggestions) >= $maxSuggestions) {
                    break 2;
                }
                $this->fillDaySlots($day, $wh, $duration, $appointments, $suggestions, $maxSuggestions);
            }
        }

        return $suggestions;
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @param  list<array{start: Carbon, end: Carbon}>  $suggestions
     */
    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array{start: Carbon, end: Carbon}>
     */
    private function slotsForWorkingWindow(
        Carbon $day,
        WorkingHour $wh,
        int $durationMinutes,
        Collection $appointments,
    ): array {
        $open = Carbon::parse($day->format('Y-m-d').' '.$wh->opens_at);
        $close = Carbon::parse($day->format('Y-m-d').' '.$wh->closes_at);
        if ($close->lte($open)) {
            return [];
        }

        $slots = [];
        $cursor = $open->copy();
        while ($cursor->copy()->addMinutes($durationMinutes)->lte($close)) {
            $slotEnd = $cursor->copy()->addMinutes($durationMinutes);
            if ($cursor->lt(now())) {
                $cursor->addMinutes(15);

                continue;
            }
            if ($this->overlapsBusy($cursor, $slotEnd, $appointments)) {
                $cursor->addMinutes(15);

                continue;
            }
            $slots[] = ['start' => $cursor->copy(), 'end' => $slotEnd->copy()];
            $cursor->addMinutes(30);
        }

        return $slots;
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @param  list<array{start: Carbon, end: Carbon}>  $suggestions
     */
    private function fillDaySlots(
        Carbon $day,
        WorkingHour $wh,
        int $durationMinutes,
        Collection $appointments,
        array &$suggestions,
        int $maxSuggestions
    ): void {
        foreach ($this->slotsForWorkingWindow($day, $wh, $durationMinutes, $appointments) as $slot) {
            if (count($suggestions) >= $maxSuggestions) {
                return;
            }
            $suggestions[] = $slot;
        }
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     */
    private function overlapsBusy(Carbon $start, Carbon $end, Collection $appointments): bool
    {
        foreach ($appointments as $a) {
            if ($start->lt($a->ends_at) && $end->gt($a->starts_at)) {
                return true;
            }
        }

        return false;
    }

    public function isSlotFree(User $user, Carbon $start, Carbon $end, ?int $ignoreAppointmentId = null): bool
    {
        $q = Appointment::query()
            ->where('user_id', $user->id)
            ->confirmed()
            ->where(function ($query) use ($start, $end) {
                $query->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $start);
            });
        if ($ignoreAppointmentId) {
            $q->where('id', '!=', $ignoreAppointmentId);
        }

        return ! $q->exists();
    }

    public function isIntervalWithinWorkingHours(User $user, Carbon $start, Carbon $end): bool
    {
        $weekday = (int) $start->dayOfWeek;
        $rows = WorkingHour::query()
            ->where('user_id', $user->id)
            ->where('weekday', $weekday)
            ->get();
        if ($rows->isEmpty()) {
            return false;
        }
        foreach ($rows as $wh) {
            $open = Carbon::parse($start->format('Y-m-d').' '.$wh->opens_at);
            $close = Carbon::parse($start->format('Y-m-d').' '.$wh->closes_at);
            if ($close->lte($open)) {
                continue;
            }
            if ($start->gte($open) && $end->lte($close)) {
                return true;
            }
        }

        return false;
    }

    public function findServiceByTitle(User $user, string $title): ?Service
    {
        $needle = mb_strtolower(trim($title));

        return Service::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get()
            ->first(function (Service $s) use ($needle) {
                return mb_strtolower($s->title) === $needle
                    || str_contains(mb_strtolower($s->title), $needle)
                    || str_contains($needle, mb_strtolower($s->title));
            });
    }
}
