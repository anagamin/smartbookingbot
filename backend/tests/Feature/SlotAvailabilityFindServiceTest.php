<?php

namespace Tests\Feature;

use App\Models\Master;
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

    public function test_find_services_by_title_returns_all_exact_matches(): void
    {
        $user = User::factory()->create();
        $masterA = Master::query()->create(['user_id' => $user->id, 'name' => 'А', 'sort_order' => 0]);
        $masterB = Master::query()->create(['user_id' => $user->id, 'name' => 'Б', 'sort_order' => 1]);

        Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $masterA->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);
        Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $masterB->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);

        $slots = app(SlotAvailabilityService::class);
        $found = $slots->findServicesByTitle($user, 'Маникюр');

        $this->assertCount(2, $found);
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

    public function test_find_services_in_dialog_returns_multiple_ordered_by_occurrence(): void
    {
        $user = User::factory()->create();
        $m = Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Маникюр',
            'duration_minutes' => 90,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);
        $p = Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Педикюр',
            'duration_minutes' => 60,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);

        $slots = app(SlotAvailabilityService::class);
        $found = $slots->findServicesInDialogText($user, 'Запишите на маникюр и педикюр на пятницу');

        $this->assertCount(2, $found);
        $this->assertTrue($found->get(0)->is($m));
        $this->assertTrue($found->get(1)->is($p));
    }
}
