<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\DatabaseTestCase;

class ProcessSubscriptionCommandTest extends DatabaseTestCase
{
    public function test_pauses_bot_when_subscription_expired(): void
    {
        $user = User::factory()->create([
            'subscription_ends_at' => now()->subHour(),
            'trial_ends_at' => now()->subMonth(),
            'bot_paused' => false,
        ]);

        Artisan::call('smartbooking:subscriptions');

        $user->refresh();
        $this->assertTrue($user->bot_paused);
    }

    public function test_does_not_pause_when_subscription_active(): void
    {
        $user = User::factory()->create([
            'subscription_ends_at' => now()->addMonth(),
            'trial_ends_at' => now()->subDay(),
            'bot_paused' => false,
        ]);

        Artisan::call('smartbooking:subscriptions');

        $user->refresh();
        $this->assertFalse($user->bot_paused);
    }
}
