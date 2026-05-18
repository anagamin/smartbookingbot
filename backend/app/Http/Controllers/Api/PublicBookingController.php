<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Master;
use App\Models\Service;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\SlotAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PublicBookingController extends Controller
{
    public function show(string $slug)
    {
        $user = $this->resolveOwner($slug);
        if ($user === null) {
            return response()->json(['message' => 'Страница записи не найдена.'], 404);
        }

        $masters = $user->masters()->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);
        $isSalon = $user->isSalon() && $masters->count() > 1;

        $servicesQuery = Service::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('title');

        return response()->json([
            'owner_name' => $user->name,
            'business_mode' => $user->business_mode ?? 'solo',
            'is_salon' => $isSalon,
            'slug' => $user->booking_slug,
            'masters' => $masters,
            'services' => $servicesQuery->get(['id', 'master_id', 'title', 'description', 'price_kopecks', 'duration_minutes']),
        ]);
    }

    public function slots(Request $request, string $slug)
    {
        $user = $this->resolveOwner($slug);
        if ($user === null) {
            return response()->json(['message' => 'Страница записи не найдена.'], 404);
        }

        $data = $request->validate([
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'distinct'],
            'master_id' => ['nullable', 'integer'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        $services = $this->resolveActiveServices($user, $data['service_ids']);
        if ($services->isEmpty()) {
            return response()->json(['message' => 'Укажите доступные услуги.'], 422);
        }

        $master = null;
        if (! empty($data['master_id'])) {
            $master = $this->resolveMaster($user, (int) $data['master_id']);
            if ($master === null) {
                return response()->json(['message' => 'Мастер не найден.'], 422);
            }
        } else {
            $mastersForServices = app(SlotAvailabilityService::class)->resolveMastersForServices($user, $services);
            if ($mastersForServices->count() === 1) {
                $master = $mastersForServices->first();
            }
        }

        $duration = max(1, $services->sum(fn (Service $s) => (int) $s->duration_minutes));
        $daysAhead = (int) ($data['days'] ?? 14);
        $slotService = app(SlotAvailabilityService::class);

        if ($master !== null) {
            $raw = $slotService->suggestSlots($user, null, $daysAhead, 72, $duration, $master);
        } else {
            $raw = $slotService->suggestSlotsForServices($user, $services, $daysAhead, 72);
        }

        $days = [];
        foreach ($raw as $slot) {
            $dateKey = $slot['start']->format('Y-m-d');
            if (! isset($days[$dateKey])) {
                $days[$dateKey] = [
                    'date' => $dateKey,
                    'label' => $slot['start']->locale('ru')->translatedFormat('j F, l'),
                    'slots' => [],
                ];
            }
            $entry = [
                'starts_at' => $slot['start']->toIso8601String(),
                'ends_at' => $slot['end']->toIso8601String(),
                'time' => $slot['start']->format('H:i'),
            ];
            if (isset($slot['master_id'])) {
                $entry['master_id'] = $slot['master_id'];
                $entry['master_name'] = $slot['master_name'] ?? null;
                $entry['time'] = $slot['start']->format('H:i').' ('.($slot['master_name'] ?? '').')';
            }
            $days[$dateKey]['slots'][] = $entry;
        }

        return response()->json([
            'duration_minutes' => $duration,
            'requires_master_choice' => $master === null && app(SlotAvailabilityService::class)->resolveMastersForServices($user, $services)->count() > 1,
            'days' => array_values($days),
        ]);
    }

    public function store(Request $request, string $slug, ActivityLogger $activityLogger)
    {
        $user = $this->resolveOwner($slug);
        if ($user === null) {
            return response()->json(['message' => 'Страница записи не найдена.'], 404);
        }

        $data = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'distinct'],
            'master_id' => ['nullable', 'integer'],
            'starts_at' => ['required', 'date'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $services = $this->resolveActiveServices($user, $data['service_ids']);
        if ($services->isEmpty()) {
            return response()->json(['message' => 'Выберите хотя бы одну услугу.'], 422);
        }

        $slotService = app(SlotAvailabilityService::class);
        $mastersForServices = $slotService->resolveMastersForServices($user, $services);

        $master = null;
        if (! empty($data['master_id'])) {
            $master = $this->resolveMaster($user, (int) $data['master_id']);
            if ($master === null) {
                return response()->json(['message' => 'Мастер не найден.'], 422);
            }
        } elseif ($mastersForServices->count() === 1) {
            $master = $mastersForServices->first();
        } else {
            return response()->json(['message' => 'Выберите мастера для записи.'], 422);
        }

        foreach ($services as $service) {
            if ($service->master_id !== null && $service->master_id !== $master->id) {
                return response()->json(['message' => 'Услуга недоступна у выбранного мастера.'], 422);
            }
        }

        $start = Carbon::parse($data['starts_at']);
        $duration = max(1, $services->sum(fn (Service $s) => (int) $s->duration_minutes));
        $end = $start->copy()->addMinutes($duration);

        if (! $slotService->isIntervalWithinWorkingHours($user, $start, $end, $master)) {
            return response()->json(['message' => 'Выбранное время вне рабочих часов.'], 422);
        }
        if (! $slotService->isSlotFree($user, $start, $end, null, $master)) {
            return response()->json(['message' => 'Это время уже занято. Выберите другое.'], 422);
        }

        $orderedIds = $services->pluck('id')->values()->all();
        $primaryId = array_shift($orderedIds);
        $price = $services->sum(fn (Service $s) => (int) ($s->price_kopecks ?? 0));

        $comment = isset($data['comment']) ? trim((string) $data['comment']) : '';

        $appointment = Appointment::query()->create([
            'user_id' => $user->id,
            'master_id' => $master->id,
            'service_id' => $primaryId,
            'extra_service_ids' => $orderedIds === [] ? null : $orderedIds,
            'client_name' => $data['client_name'],
            'starts_at' => $start,
            'ends_at' => $end,
            'price_kopecks' => $price > 0 ? $price : null,
            'chat_excerpt' => $comment !== '' ? $comment : null,
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $activityLogger->log($user, 'appointment_created', 'Запись #'.$appointment->id.' (онлайн-форма)', [
            'appointment_id' => $appointment->id,
            'source' => 'public_booking',
        ]);

        return response()->json([
            'message' => 'Запись оформлена.',
            'appointment' => [
                'id' => $appointment->id,
                'master_name' => $master->name,
                'starts_at' => $appointment->starts_at->toIso8601String(),
                'ends_at' => $appointment->ends_at->toIso8601String(),
            ],
        ], 201);
    }

    private function resolveOwner(string $slug): ?User
    {
        $normalized = strtolower(trim($slug));
        if ($normalized === '') {
            return null;
        }

        return User::query()->where('booking_slug', $normalized)->first();
    }

    private function resolveMaster(User $user, int $masterId): ?Master
    {
        return Master::query()
            ->where('user_id', $user->id)
            ->where('id', $masterId)
            ->first();
    }

    /**
     * @param  array<int>  $serviceIds
     * @return Collection<int, Service>
     */
    private function resolveActiveServices(User $user, array $serviceIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $serviceIds)));

        return Service::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->orderBy('title')
            ->get();
    }
}
