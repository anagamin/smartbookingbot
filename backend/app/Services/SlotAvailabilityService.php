<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Master;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class SlotAvailabilityService
{
    /**
     * @return list<array{start: Carbon, end: Carbon, master_id?: int, master_name?: string}>
     */
    /**
     * @param  Collection<int, Service>|null  $services  When set, only masters linked to these services are considered.
     */
    public function suggestSlotsInDateRange(
        User $user,
        ?Service $service,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        int $maxSuggestions = 60,
        ?int $durationMinutesOverride = null,
        ?Master $master = null,
        ?Collection $services = null,
    ): array {
        if ($master !== null) {
            return $this->suggestSlotsInDateRangeForMaster(
                $user,
                $master,
                $service,
                $rangeStart,
                $rangeEnd,
                $maxSuggestions,
                $durationMinutesOverride,
            );
        }

        $servicesForMasters = $services !== null && $services->isNotEmpty()
            ? $services
            : ($service !== null ? collect([$service]) : collect());

        $masters = $this->resolveMastersForAvailability($user, $servicesForMasters);

        if ($masters->count() <= 1) {
            $single = $masters->first();
            if ($single === null) {
                if ($servicesForMasters->isNotEmpty()) {
                    return [];
                }
                $single = $user->primaryMaster();
            }

            return $single !== null
                ? $this->suggestSlotsInDateRangeForMaster($user, $single, $service, $rangeStart, $rangeEnd, $maxSuggestions, $durationMinutesOverride)
                : [];
        }

        return $this->suggestSlotsMergedForMasters($user, $masters, $service, $rangeStart, $rangeEnd, $maxSuggestions, $durationMinutesOverride);
    }

    /**
     * @return list<array{start: Carbon, end: Carbon, master_id?: int, master_name?: string}>
     */
    public function suggestSlots(
        User $user,
        ?Service $service,
        int $daysAhead = 14,
        int $maxSuggestions = 24,
        ?int $durationMinutesOverride = null,
        ?Master $master = null,
    ): array {
        $end = now()->addDays($daysAhead)->endOfDay();

        return $this->suggestSlotsInDateRange(
            $user,
            $service,
            now()->startOfDay(),
            $end,
            $maxSuggestions,
            $durationMinutesOverride,
            $master,
        );
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return list<array{start: Carbon, end: Carbon, master_id?: int, master_name?: string}>
     */
    public function suggestSlotsForServices(
        User $user,
        Collection $services,
        int $daysAhead = 14,
        int $maxSuggestions = 48,
        ?Master $master = null,
    ): array {
        $duration = max(1, $services->sum(fn (Service $s) => (int) $s->duration_minutes));
        $singleService = $services->count() === 1 ? $services->first() : null;
        $durationOverride = $services->count() > 1 ? $duration : null;

        if ($master !== null) {
            return $this->suggestSlots($user, $singleService, $daysAhead, $maxSuggestions, $durationOverride, $master);
        }

        $masters = $this->resolveMastersForAvailability($user, $services);

        if ($masters->count() === 1) {
            return $this->suggestSlots($user, $singleService, $daysAhead, $maxSuggestions, $durationOverride, $masters->first());
        }

        if ($masters->count() > 1) {
            return $this->suggestSlotsMergedForMasters(
                $user,
                $masters,
                $singleService,
                now()->startOfDay(),
                now()->addDays($daysAhead)->endOfDay(),
                $maxSuggestions,
                $durationOverride,
            );
        }

        if ($services->isNotEmpty()) {
            return [];
        }

        return $this->suggestSlots($user, $singleService, $daysAhead, $maxSuggestions, $durationOverride, $user->primaryMaster());
    }

    /**
     * @return list<array{start: Carbon, end: Carbon}>
     */
    private function suggestSlotsInDateRangeForMaster(
        User $user,
        Master $master,
        ?Service $service,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        int $maxSuggestions,
        ?int $durationMinutesOverride,
    ): array {
        $duration = $durationMinutesOverride ?? $service?->duration_minutes ?? 60;
        $working = $this->workingHoursForMaster($user, $master)->groupBy('weekday');
        $appointments = $this->appointmentsForMaster($master);

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
     * @return list<array{start: Carbon, end: Carbon, master_id: int, master_name: string}>
     */
    private function suggestSlotsMergedForMasters(
        User $user,
        Collection $masters,
        ?Service $service,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        int $maxSuggestions,
        ?int $durationMinutesOverride,
    ): array {
        $merged = [];
        foreach ($masters as $master) {
            foreach ($this->suggestSlotsInDateRangeForMaster(
                $user,
                $master,
                $service,
                $rangeStart,
                $rangeEnd,
                $maxSuggestions,
                $durationMinutesOverride,
            ) as $slot) {
                $merged[] = [
                    'start' => $slot['start'],
                    'end' => $slot['end'],
                    'master_id' => $master->id,
                    'master_name' => $master->name,
                ];
            }
        }

        usort($merged, fn (array $a, array $b): int => $a['start']->timestamp <=> $b['start']->timestamp);

        return array_slice($merged, 0, $maxSuggestions);
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return Collection<int, Master>
     */
    public function resolveMastersForServices(User $user, Collection $services): Collection
    {
        if ($user->isSolo()) {
            $primary = $user->primaryMaster();

            return $primary !== null ? collect([$primary]) : collect();
        }

        if ($services->isEmpty()) {
            return $user->masters()->orderBy('sort_order')->orderBy('id')->get();
        }

        $masterIds = $services->pluck('master_id')->filter()->unique()->values();

        if ($masterIds->isEmpty()) {
            $primary = $user->primaryMaster();

            return $primary !== null ? collect([$primary]) : collect();
        }

        return Master::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $masterIds->all())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Мастера для подбора слотов: в solo — один основной; в салоне — с графиком, с запасным вариантом если у услуги неверный master_id.
     *
     * @param  Collection<int, Service>  $services
     * @return Collection<int, Master>
     */
    /**
     * Если у нескольких мастеров одна и та же услуга (одинаковое название), учитываем всех при подборе слотов.
     *
     * @param  Collection<int, Service>  $services
     * @return Collection<int, Service>
     */
    public function expandServicesWithSameTitle(User $user, Collection $services): Collection
    {
        if ($services->isEmpty()) {
            return $services;
        }

        $titles = $services
            ->map(fn (Service $s) => mb_strtolower(trim($s->title)))
            ->filter(fn (string $t) => $t !== '')
            ->unique()
            ->values();

        if ($titles->isEmpty()) {
            return $services;
        }

        return Service::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Service $s) => $titles->contains(mb_strtolower(trim($s->title))))
            ->unique('id')
            ->values();
    }

    public function resolveMastersForAvailability(User $user, Collection $services): Collection
    {
        $services = $this->expandServicesWithSameTitle($user, $services);
        $masters = $this->resolveMastersForServices($user, $services);
        $withSchedule = $masters->filter(fn (Master $m) => $this->masterHasWorkingHours($user, $m))->values();

        if ($withSchedule->isNotEmpty()) {
            return $withSchedule;
        }

        if ($services->isNotEmpty()) {
            return $masters;
        }

        $anyWithSchedule = $user->masters()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (Master $m) => $this->masterHasWorkingHours($user, $m))
            ->values();

        return $anyWithSchedule->isNotEmpty() ? $anyWithSchedule : $masters;
    }

    public function masterHasWorkingHours(User $user, Master $master): bool
    {
        if (WorkingHour::query()->where('master_id', $master->id)->exists()) {
            return true;
        }

        return WorkingHour::query()
            ->where('user_id', $user->id)
            ->whereNull('master_id')
            ->exists();
    }

    /**
     * @return Collection<int, WorkingHour>
     */
    private function workingHoursForMaster(User $user, Master $master): Collection
    {
        $hours = WorkingHour::query()->where('master_id', $master->id)->get();
        if ($hours->isNotEmpty()) {
            return $hours;
        }

        return WorkingHour::query()
            ->where('user_id', $user->id)
            ->whereNull('master_id')
            ->get();
    }

    public function findMasterByName(User $user, string $name): ?Master
    {
        $needle = mb_strtolower(trim($name));
        if ($needle === '') {
            return null;
        }

        $masters = $user->masters()->get();
        $exact = $masters->first(fn (Master $m) => mb_strtolower($m->name) === $needle);
        if ($exact !== null) {
            return $exact;
        }

        return $masters
            ->filter(fn (Master $m) => str_contains(mb_strtolower($m->name), $needle) || str_contains($needle, mb_strtolower($m->name)))
            ->sortByDesc(fn (Master $m) => mb_strlen($m->name))
            ->first();
    }

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

    public function isSlotFree(
        User $user,
        Carbon $start,
        Carbon $end,
        ?int $ignoreAppointmentId = null,
        ?Master $master = null,
    ): bool {
        $q = Appointment::query()
            ->where('user_id', $user->id)
            ->confirmed()
            ->where(function ($query) use ($start, $end) {
                $query->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $start);
            });

        if ($master !== null) {
            $q->where('master_id', $master->id);
        }

        if ($ignoreAppointmentId) {
            $q->where('id', '!=', $ignoreAppointmentId);
        }

        return ! $q->exists();
    }

    public function isIntervalWithinWorkingHours(User $user, Carbon $start, Carbon $end, ?Master $master = null): bool
    {
        $master = $master ?? $user->primaryMaster();
        if ($master === null) {
            return false;
        }

        $weekday = (int) $start->dayOfWeek;
        $rows = $this->workingHoursForMaster($user, $master)
            ->where('weekday', $weekday);
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

    /** @return Collection<int, Appointment> */
    private function appointmentsForMaster(Master $master): Collection
    {
        return Appointment::query()
            ->where('master_id', $master->id)
            ->confirmed()
            ->where('ends_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return Collection<int, Service>
     */
    public function findServicesByTitle(User $user, string $title, ?Master $master = null): Collection
    {
        $needle = mb_strtolower(trim($title));
        if ($needle === '') {
            return collect();
        }

        $q = Service::query()
            ->where('user_id', $user->id)
            ->where('is_active', true);
        if ($master !== null) {
            $q->where('master_id', $master->id);
        }
        $candidates = $q->get();

        $exact = $candidates->filter(fn (Service $s) => mb_strtolower($s->title) === $needle)->values();
        if ($exact->isNotEmpty()) {
            return $exact;
        }

        $partial = $candidates->filter(function (Service $s) use ($needle) {
            $t = mb_strtolower($s->title);

            return str_contains($t, $needle) || str_contains($needle, $t);
        });

        return $partial->sortByDesc(fn (Service $s) => mb_strlen($s->title))->values();
    }

    public function findServiceByTitle(User $user, string $title, ?Master $master = null): ?Service
    {
        return $this->findServicesByTitle($user, $title, $master)->first();
    }

    /**
     * @return Collection<int, Service>
     */
    public function findServicesInDialogText(User $user, string $text, ?Master $master = null): Collection
    {
        $text = trim($text);
        if ($text === '') {
            return collect();
        }

        $lower = mb_strtolower($text);
        $q = Service::query()
            ->where('user_id', $user->id)
            ->where('is_active', true);
        if ($master !== null) {
            $q->where('master_id', $master->id);
        }
        $candidates = $q->get();

        $positions = [];
        foreach ($candidates as $s) {
            $needle = mb_strtolower($s->title);
            if (mb_strlen($needle) < 2) {
                continue;
            }
            $pos = mb_strpos($lower, $needle);
            if ($pos !== false) {
                $positions[$s->id] = ['pos' => $pos, 'service' => $s];
            }
        }

        if ($positions !== []) {
            uasort($positions, fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);

            return collect($positions)->map(fn (array $row) => $row['service'])->values();
        }

        $byDiscovery = collect();
        $seen = [];
        foreach (preg_split('/\R+/u', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $hit = $this->findServiceByTitle($user, $line, $master);
            if ($hit !== null && ! isset($seen[$hit->id])) {
                $seen[$hit->id] = true;
                $byDiscovery->push($hit);
            }
        }
        if ($byDiscovery->isNotEmpty()) {
            return $byDiscovery;
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_unique($words));
        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($words as $w) {
            if (mb_strlen($w) < 3) {
                continue;
            }
            $hit = $this->findServiceByTitle($user, $w, $master);
            if ($hit !== null && ! isset($seen[$hit->id])) {
                $seen[$hit->id] = true;
                $byDiscovery->push($hit);
            }
        }

        return $byDiscovery;
    }

    public function findServiceInDialogText(User $user, string $text, ?Master $master = null): ?Service
    {
        return $this->findServicesInDialogText($user, $text, $master)->first();
    }
}
