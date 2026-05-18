<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
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

        $services = Service::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'title', 'description', 'price_kopecks', 'duration_minutes']);

        return response()->json([
            'owner_name' => $user->name,
            'slug' => $user->booking_slug,
            'services' => $services,
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
            'days' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        $services = $this->resolveActiveServices($user, $data['service_ids']);
        if ($services->isEmpty()) {
            return response()->json(['message' => 'Укажите доступные услуги.'], 422);
        }

        $duration = max(1, $services->sum(fn (Service $s) => (int) $s->duration_minutes));
        $daysAhead = (int) ($data['days'] ?? 14);
        $slotService = app(SlotAvailabilityService::class);
        $raw = $slotService->suggestSlots($user, null, $daysAhead, 72, $duration);

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
            $days[$dateKey]['slots'][] = [
                'starts_at' => $slot['start']->toIso8601String(),
                'ends_at' => $slot['end']->toIso8601String(),
                'time' => $slot['start']->format('H:i'),
            ];
        }

        return response()->json([
            'duration_minutes' => $duration,
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
            'starts_at' => ['required', 'date'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $services = $this->resolveActiveServices($user, $data['service_ids']);
        if ($services->isEmpty()) {
            return response()->json(['message' => 'Выберите хотя бы одну услугу.'], 422);
        }

        $start = Carbon::parse($data['starts_at']);
        $duration = max(1, $services->sum(fn (Service $s) => (int) $s->duration_minutes));
        $end = $start->copy()->addMinutes($duration);

        $slotService = app(SlotAvailabilityService::class);
        if (! $slotService->isIntervalWithinWorkingHours($user, $start, $end)) {
            return response()->json(['message' => 'Выбранное время вне рабочих часов.'], 422);
        }
        if (! $slotService->isSlotFree($user, $start, $end)) {
            return response()->json(['message' => 'Это время уже занято. Выберите другое.'], 422);
        }

        $orderedIds = $services->pluck('id')->values()->all();
        $primaryId = array_shift($orderedIds);
        $price = $services->sum(fn (Service $s) => (int) ($s->price_kopecks ?? 0));

        $comment = isset($data['comment']) ? trim((string) $data['comment']) : '';

        $appointment = Appointment::query()->create([
            'user_id' => $user->id,
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
