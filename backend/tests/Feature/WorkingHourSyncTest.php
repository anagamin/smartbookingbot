<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkingHour;
use Laravel\Sanctum\Sanctum;
use Tests\DatabaseTestCase;

class WorkingHourSyncTest extends DatabaseTestCase
{
    public function test_sync_working_hours_with_hh_mm_format(): void
    {
        $user = User::factory()->create();
        $master = $this->primaryMaster($user);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/working-hours', [
            'master_id' => $master->id,
            'slots' => [
                ['weekday' => 1, 'opens_at' => '10:00', 'closes_at' => '19:00'],
                ['weekday' => 2, 'opens_at' => '11:00', 'closes_at' => '20:00'],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('working_hours', 2);
        $this->assertDatabaseHas('working_hours', [
            'master_id' => $master->id,
            'weekday' => 1,
        ]);
    }

    public function test_sync_accepts_hh_mm_ss_format(): void
    {
        $user = User::factory()->create();
        $master = $this->primaryMaster($user);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/working-hours', [
            'master_id' => $master->id,
            'slots' => [
                ['weekday' => 1, 'opens_at' => '10:00:00', 'closes_at' => '19:00:00'],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('working_hours', [
            'master_id' => $master->id,
            'weekday' => 1,
            'opens_at' => '10:00:00',
            'closes_at' => '19:00:00',
        ]);
    }

    public function test_index_falls_back_to_legacy_hours_without_master_id(): void
    {
        $user = User::factory()->create();
        $master = $this->primaryMaster($user);
        WorkingHour::query()->create([
            'user_id' => $user->id,
            'master_id' => null,
            'weekday' => 3,
            'opens_at' => '09:00:00',
            'closes_at' => '17:00:00',
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/working-hours?master_id='.$master->id);

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.weekday', 3);
    }
}
