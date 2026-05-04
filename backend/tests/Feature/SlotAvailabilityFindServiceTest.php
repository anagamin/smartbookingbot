<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Services\SlotAvailabilityService;
use Tests\DatabaseTestCase;

class SlotAvailabilityFindServiceTest extends DatabaseTestCase
{
    public function test_find_service_in_dialog_matches_partial_client_word(): void
    {
        $user = User::factory()->create();
        Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Маникюр гель-лак',
            'duration_minutes' => 120,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);
        Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Педикюр',
            'duration_minutes' => 60,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);

        $slots = app(SlotAvailabilityService::class);
        $found = $slots->findServiceInDialogText($user, 'Здравствуйте, хочу маникюр на пятницу');

        $this->assertNotNull($found);
        $this->assertSame('Маникюр гель-лак', $found->title);
    }

    public function test_find_service_by_title_prefers_exact_over_longer_partial(): void
    {
        $user = User::factory()->create();
        Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);
        Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Маникюр гель-лак',
            'duration_minutes' => 120,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);

        $slots = app(SlotAvailabilityService::class);
        $found = $slots->findServiceByTitle($user, 'Маникюр');

        $this->assertNotNull($found);
        $this->assertSame('Маникюр', $found->title);
    }
}
