<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\DatabaseTestCase;

class AppointmentConflictTest extends DatabaseTestCase
{
    public function test_cannot_create_overlapping_appointments(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $start = Carbon::parse('2026-06-01 10:00:00');
        $end = Carbon::parse('2026-06-01 11:00:00');

        Appointment::query()->create([
            'user_id' => $user->id,
            'client_name' => 'A',
            'starts_at' => $start,
            'ends_at' => $end,
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $response = $this->postJson('/api/appointments', [
            'client_name' => 'B',
            'starts_at' => $start->copy()->addMinutes(30)->toIso8601String(),
            'ends_at' => $end->copy()->addMinutes(30)->toIso8601String(),
        ]);

        $response->assertStatus(422);
    }
}
