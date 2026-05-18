<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Master;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\DatabaseTestCase;

class ProfileBusinessModeTest extends DatabaseTestCase
{
    public function test_can_switch_from_salon_to_solo_with_multiple_masters(): void
    {
        $user = User::factory()->create([
            'name' => 'Салон Красоты',
            'business_mode' => 'salon',
        ]);
        $primary = $this->primaryMaster($user);

        $extra = Master::query()->create([
            'user_id' => $user->id,
            'name' => 'Анна',
            'sort_order' => 1,
        ]);

        $service = Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $extra->id,
            'title' => 'Стрижка',
            'duration_minutes' => 60,
            'price_kopecks' => 1000,
            'is_active' => true,
        ]);
        WorkingHour::query()->create([
            'user_id' => $user->id,
            'master_id' => $extra->id,
            'weekday' => 1,
            'opens_at' => '09:00:00',
            'closes_at' => '18:00:00',
        ]);
        $appointment = Appointment::query()->create([
            'user_id' => $user->id,
            'master_id' => $extra->id,
            'service_id' => $service->id,
            'client_name' => 'Клиент',
            'starts_at' => Carbon::parse('2026-05-20 14:00:00'),
            'ends_at' => Carbon::parse('2026-05-20 15:00:00'),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', ['business_mode' => 'solo'])
            ->assertOk()
            ->assertJsonPath('user.business_mode', 'solo');

        $user->refresh();
        $this->assertSame('solo', $user->business_mode);
        $this->assertSame($primary->name, $user->name);
        $this->assertCount(1, $user->masters);

        $this->assertSame($primary->id, $service->fresh()->master_id);
        $this->assertSame($primary->id, WorkingHour::query()->where('user_id', $user->id)->value('master_id'));
        $this->assertSame($primary->id, $appointment->fresh()->master_id);
        $this->assertNull(Master::query()->find($extra->id));
    }
}
