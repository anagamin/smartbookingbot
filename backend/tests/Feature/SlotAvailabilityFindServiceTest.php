<?php

namespace Tests\Feature;

use App\Models\Master;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\SlotAvailabilityService;
use Carbon\Carbon;
use Tests\DatabaseTestCase;

class SlotAvailabilityFindServiceTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    public function test_resolve_masters_for_availability_does_not_fallback_to_unrelated_staff(): void
    {
        $user = User::factory()->create(['business_mode' => 'salon']);
        $manicureMaster = Master::query()->create(['user_id' => $user->id, 'name' => 'Юрий', 'sort_order' => 0]);
        $otherMaster = Master::query()->create(['user_id' => $user->id, 'name' => 'Оксана', 'sort_order' => 1]);

        Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $manicureMaster->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);
        Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $otherMaster->id,
            'title' => 'Стрижка',
            'duration_minutes' => 60,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);

        WorkingHour::query()->create([
            'user_id' => $user->id,
            'master_id' => $otherMaster->id,
            'weekday' => 1,
            'opens_at' => '10:00:00',
            'closes_at' => '18:00:00',
        ]);

        $manicure = Service::query()->where('title', 'Маникюр')->first();
        $slots = app(SlotAvailabilityService::class);
        $masters = $slots->resolveMastersForAvailability($user, collect([$manicure]));

        $this->assertCount(1, $masters);
        $this->assertTrue($masters->first()->is($manicureMaster));
    }

    public function test_suggest_slots_in_date_range_uses_only_masters_for_given_services(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-18 10:00:00'));

        $user = User::factory()->create(['business_mode' => 'salon']);
        $yuri = Master::query()->create(['user_id' => $user->id, 'name' => 'Юрий', 'sort_order' => 0]);
        $maxim = Master::query()->create(['user_id' => $user->id, 'name' => 'Максим', 'sort_order' => 1]);
        $oksana = Master::query()->create(['user_id' => $user->id, 'name' => 'Оксана', 'sort_order' => 2]);

        foreach ([1, 2] as $weekday) {
            WorkingHour::query()->create([
                'user_id' => $user->id,
                'master_id' => $yuri->id,
                'weekday' => $weekday,
                'opens_at' => '10:00:00',
                'closes_at' => '14:00:00',
            ]);
        }
        foreach ([4, 5] as $weekday) {
            WorkingHour::query()->create([
                'user_id' => $user->id,
                'master_id' => $maxim->id,
                'weekday' => $weekday,
                'opens_at' => '10:00:00',
                'closes_at' => '14:00:00',
            ]);
        }
        foreach ([2, 3] as $weekday) {
            WorkingHour::query()->create([
                'user_id' => $user->id,
                'master_id' => $oksana->id,
                'weekday' => $weekday,
                'opens_at' => '10:00:00',
                'closes_at' => '14:00:00',
            ]);
        }

        $manicureYuri = Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $yuri->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);
        $manicureMaxim = Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $maxim->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);
        Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $oksana->id,
            'title' => 'Стрижка',
            'duration_minutes' => 60,
            'price_kopecks' => 0,
            'is_active' => true,
        ]);

        $slots = app(SlotAvailabilityService::class);
        $resolved = collect([$manicureYuri, $manicureMaxim]);
        $suggestions = $slots->suggestSlotsInDateRange(
            $user,
            null,
            Carbon::parse('2026-05-18'),
            Carbon::parse('2026-05-24'),
            80,
            null,
            null,
            $resolved,
        );

        $names = array_map(fn (array $s) => $s['master_name'] ?? '', $suggestions);
        $this->assertNotEmpty($suggestions);
        $this->assertContains('Юрий', $names);
        $this->assertContains('Максим', $names);
        $this->assertNotContains('Оксана', $names);
    }
}
