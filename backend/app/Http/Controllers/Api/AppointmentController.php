<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Services\SlotAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($request->query('from'))->startOfDay();
        $to = Carbon::parse($request->query('to'))->endOfDay();

        $items = Appointment::query()
            ->where('user_id', $request->user()->id)
            ->where('ends_at', '>=', $from)
            ->where('starts_at', '<=', $to)
            ->with('service')
            ->orderBy('starts_at')
            ->get();

        $extraIds = $items
            ->flatMap(fn (Appointment $a) => collect($a->extra_service_ids ?? []))
            ->unique()
            ->filter()
            ->values()
            ->all();
        $extraServiceById = $extraIds === []
            ? collect()
            : Service::query()
                ->where('user_id', $request->user()->id)
                ->whereIn('id', $extraIds)
                ->get()
                ->keyBy('id');

        return $items->map(fn (Appointment $a) => $this->toCalendarEvent($a, $extraServiceById));
    }

    public function store(Request $request)
    {
        $hasActiveServices = Service::query()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->exists();

        $data = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'service_id' => $hasActiveServices
                ? ['required', 'integer', 'exists:services,id']
                : ['nullable', 'integer', 'exists:services,id'],
            'extra_service_ids' => ['nullable', 'array'],
            'extra_service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'price_kopecks' => ['nullable', 'integer', 'min:0'],
            'chat_excerpt' => ['nullable', 'string'],
        ]);

        $mainId = $data['service_id'] ?? null;
        $extraIds = array_values(array_unique(array_map('intval', $data['extra_service_ids'] ?? [])));
        $extraIds = array_values(array_filter($extraIds, fn (int $id) => $id !== $mainId));
        $this->validateServiceOwnership($request, $mainId);
        $this->validateExtraServiceOwnership($request, $extraIds);

        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);
        if (! app(SlotAvailabilityService::class)->isSlotFree($request->user(), $start, $end)) {
            return response()->json(['message' => 'Пересечение с другой записью.'], 422);
        }

        $appointment = Appointment::query()->create([
            'user_id' => $request->user()->id,
            'client_name' => $data['client_name'],
            'service_id' => $data['service_id'] ?? null,
            'extra_service_ids' => $extraIds === [] ? null : $extraIds,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'price_kopecks' => $data['price_kopecks'] ?? null,
            'chat_excerpt' => $data['chat_excerpt'] ?? null,
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $extraMap = collect($appointment->extra_service_ids ?? [])->filter()->isEmpty()
            ? collect()
            : Service::query()
                ->where('user_id', $request->user()->id)
                ->whereIn('id', $appointment->extra_service_ids ?? [])
                ->get()
                ->keyBy('id');

        return response()->json($this->toCalendarEvent($appointment->load('service'), $extraMap), 201);
    }

    public function show(Request $request, Appointment $appointment)
    {
        $this->authorizeOwner($request, $appointment);

        return response()->json($this->toDetail($appointment->load(['service', 'dialogSession.messages'])));
    }

    public function update(Request $request, Appointment $appointment)
    {
        $this->authorizeOwner($request, $appointment);

        $data = $request->validate([
            'client_name' => ['sometimes', 'string', 'max:255'],
            'service_id' => ['nullable', 'exists:services,id'],
            'extra_service_ids' => ['nullable', 'array'],
            'extra_service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'price_kopecks' => ['nullable', 'integer', 'min:0'],
            'chat_excerpt' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:confirmed,cancelled'],
        ]);

        $mainId = array_key_exists('service_id', $data) ? $data['service_id'] : $appointment->service_id;
        if (array_key_exists('extra_service_ids', $data)) {
            $extraIds = array_values(array_unique(array_map('intval', $data['extra_service_ids'] ?? [])));
            $extraIds = array_values(array_filter($extraIds, fn (int $id) => $id !== (int) ($mainId ?? 0)));
            $data['extra_service_ids'] = $extraIds === [] ? null : $extraIds;
            $this->validateExtraServiceOwnership($request, $extraIds);
        }

        $this->validateServiceOwnership($request, $data['service_id'] ?? $appointment->service_id);

        $hasActiveServices = Service::query()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->exists();
        if ($hasActiveServices && array_key_exists('service_id', $data) && ($data['service_id'] ?? null) === null) {
            return response()->json(['message' => 'Укажите услугу.'], 422);
        }

        if (isset($data['starts_at']) || isset($data['ends_at'])) {
            $start = Carbon::parse($data['starts_at'] ?? $appointment->starts_at);
            $end = Carbon::parse($data['ends_at'] ?? $appointment->ends_at);
            if (! app(SlotAvailabilityService::class)->isSlotFree($request->user(), $start, $end, $appointment->id)) {
                return response()->json(['message' => 'Пересечение с другой записью.'], 422);
            }
        }

        $appointment->update($data);

        return response()->json($this->toDetail($appointment->fresh()->load(['service', 'dialogSession.messages'])));
    }

    private function validateServiceOwnership(Request $request, ?int $serviceId): void
    {
        if ($serviceId === null) {
            return;
        }
        $exists = Service::query()->where('id', $serviceId)->where('user_id', $request->user()->id)->exists();
        abort_if(! $exists, 422, 'Invalid service');
    }

    /**
     * @param  array<int>  $serviceIds
     */
    private function validateExtraServiceOwnership(Request $request, array $serviceIds): void
    {
        foreach ($serviceIds as $id) {
            $this->validateServiceOwnership($request, $id);
        }
    }

    private function authorizeOwner(Request $request, Appointment $appointment): void
    {
        abort_if($appointment->user_id !== $request->user()->id, 403);
    }

    private function toCalendarEvent(Appointment $a, ?Collection $extraServiceById = null): array
    {
        $extraServiceById = $extraServiceById ?? collect();
        $titles = collect();
        if ($a->service !== null) {
            $titles->push($a->service->title);
        }
        foreach ($a->extra_service_ids ?? [] as $extraId) {
            $ex = $extraServiceById->get((int) $extraId);
            if ($ex !== null) {
                $titles->push($ex->title);
            }
        }
        $servicePart = $titles->isNotEmpty() ? ' — '.$titles->implode(' + ') : '';
        $title = $a->client_name.$servicePart;

        return [
            'id' => (string) $a->id,
            'title' => $title,
            'start' => $a->starts_at->toIso8601String(),
            'end' => $a->ends_at->toIso8601String(),
            'classNames' => $a->status === Appointment::STATUS_CANCELLED ? ['sb-event-cancelled'] : [],
            'extendedProps' => [
                'client_name' => $a->client_name,
                'status' => $a->status,
                'service_id' => $a->service_id,
                'extra_service_ids' => $a->extra_service_ids ?? [],
                'dialog_session_id' => $a->dialog_session_id,
                'price_kopecks' => $a->price_kopecks,
            ],
        ];
    }

    private function toDetail(Appointment $a): array
    {
        $extraIds = collect($a->extra_service_ids ?? [])->filter()->values()->all();
        $extraServices = $extraIds === []
            ? collect()
            : Service::query()
                ->where('user_id', $a->user_id)
                ->whereIn('id', $extraIds)
                ->get()
                ->keyBy('id');
        $extraOrdered = collect($extraIds)
            ->map(fn (int|string $id) => $extraServices->get((int) $id))
            ->filter()
            ->values();

        return [
            'id' => $a->id,
            'client_name' => $a->client_name,
            'service_id' => $a->service_id,
            'extra_service_ids' => $a->extra_service_ids ?? [],
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at->toIso8601String(),
            'status' => $a->status,
            'price_kopecks' => $a->price_kopecks,
            'chat_excerpt' => $a->chat_excerpt,
            'service' => $a->service,
            'extra_services' => $extraOrdered->map(fn (Service $s) => [
                'id' => $s->id,
                'title' => $s->title,
            ])->values()->all(),
            'dialog_session_id' => $a->dialog_session_id,
            'messages' => $a->dialogSession?->messages()
                ->orderBy('id')
                ->get(['id', 'direction', 'text', 'created_at']) ?? [],
        ];
    }
}
