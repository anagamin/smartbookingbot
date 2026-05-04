<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Services\SlotAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;

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

        return $items->map(fn (Appointment $a) => $this->toCalendarEvent($a));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'service_id' => ['nullable', 'exists:services,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'price_kopecks' => ['nullable', 'integer', 'min:0'],
            'chat_excerpt' => ['nullable', 'string'],
        ]);

        $this->validateServiceOwnership($request, $data['service_id'] ?? null);

        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);
        if (! app(SlotAvailabilityService::class)->isSlotFree($request->user(), $start, $end)) {
            return response()->json(['message' => 'Пересечение с другой записью.'], 422);
        }

        $appointment = Appointment::query()->create([
            'user_id' => $request->user()->id,
            'client_name' => $data['client_name'],
            'service_id' => $data['service_id'] ?? null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'price_kopecks' => $data['price_kopecks'] ?? null,
            'chat_excerpt' => $data['chat_excerpt'] ?? null,
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        return response()->json($this->toCalendarEvent($appointment->load('service')), 201);
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
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'price_kopecks' => ['nullable', 'integer', 'min:0'],
            'chat_excerpt' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:confirmed,cancelled'],
        ]);

        $this->validateServiceOwnership($request, $data['service_id'] ?? $appointment->service_id);

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

    private function authorizeOwner(Request $request, Appointment $appointment): void
    {
        abort_if($appointment->user_id !== $request->user()->id, 403);
    }

    private function toCalendarEvent(Appointment $a): array
    {
        $title = $a->client_name.($a->service ? ' — '.$a->service->title : '');

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
                'dialog_session_id' => $a->dialog_session_id,
                'price_kopecks' => $a->price_kopecks,
            ],
        ];
    }

    private function toDetail(Appointment $a): array
    {
        return [
            'id' => $a->id,
            'client_name' => $a->client_name,
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at->toIso8601String(),
            'status' => $a->status,
            'price_kopecks' => $a->price_kopecks,
            'chat_excerpt' => $a->chat_excerpt,
            'service' => $a->service,
            'dialog_session_id' => $a->dialog_session_id,
            'messages' => $a->dialogSession?->messages()
                ->orderBy('id')
                ->get(['id', 'direction', 'text', 'created_at']) ?? [],
        ];
    }
}
