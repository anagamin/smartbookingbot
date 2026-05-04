<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\DatabaseTestCase;

class ProcessSubscriptionCommandTest extends DatabaseTestCase
{
    public function test_pauses_bot_when_balance_insufficient_after_trial(): void
    {
        $user = User::factory()->create([
            'balance_kopecks' => 0,
            'trial_ends_at' => now()->subDay(),
            'next_billing_at' => now()->subHour(),
            'bot_paused' => false,
        ]);

        Artisan::call('smartbooking:subscriptions');

        $user->refresh();
        $this->assertTrue($user->bot_paused);
    }

    public function test_deducts_when_balance_sufficient(): void
    {
        $price = (int) config('smartbooking.subscription_price_kopecks', 100_000);
        $user = User::factory()->create([
            'balance_kopecks' => $price + 500,
            'trial_ends_at' => now()->subDay(),
            'next_billing_at' => now()->subHour(),
            'bot_paused' => false,
        ]);

        Artisan::call('smartbooking:subscriptions');

        $user->refresh();
        $this->assertFalse($user->bot_paused);
        $this->assertSame(500, $user->balance_kopecks);
        $this->assertNotNull($user->next_billing_at);
        $this->assertTrue($user->next_billing_at->isFuture());
    }
}
