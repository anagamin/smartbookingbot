<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\DatabaseTestCase;

class ProfileBotToggleTest extends DatabaseTestCase
{
    public function test_user_payload_includes_subscription_active(): void
    {
        $price = (int) config('smartbooking.subscription_price_kopecks', 100_000);
        $trialUser = User::factory()->create([
            'trial_ends_at' => now()->addDay(),
            'balance_kopecks' => 0,
            'bot_paused' => true,
        ]);
        Sanctum::actingAs($trialUser);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('subscription_active', true)
            ->assertJsonPath('bot_paused', true);

        $paidUser = User::factory()->create([
            'trial_ends_at' => now()->subDay(),
            'balance_kopecks' => $price,
            'bot_paused' => false,
        ]);
        Sanctum::actingAs($paidUser);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('subscription_active', true);

        $inactiveUser = User::factory()->create([
            'trial_ends_at' => now()->subDay(),
            'balance_kopecks' => $price - 1,
            'bot_paused' => true,
        ]);
        Sanctum::actingAs($inactiveUser);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('subscription_active', false);
    }

    public function test_cannot_unpause_bot_without_subscription(): void
    {
        $user = User::factory()->create([
            'trial_ends_at' => now()->subDay(),
            'balance_kopecks' => 0,
            'bot_paused' => true,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', ['bot_paused' => false])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Включить бота можно только при активной подписке или в триальном периоде.']);

        $user->refresh();
        $this->assertTrue($user->bot_paused);
    }

    public function test_can_unpause_bot_during_trial(): void
    {
        $user = User::factory()->create([
            'trial_ends_at' => now()->addDay(),
            'bot_paused' => true,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', ['bot_paused' => false])
            ->assertOk()
            ->assertJsonPath('user.bot_paused', false)
            ->assertJsonPath('user.subscription_active', true);

        $this->assertFalse($user->fresh()->bot_paused);
    }

    public function test_can_pause_bot_even_without_subscription(): void
    {
        $user = User::factory()->create([
            'trial_ends_at' => now()->subDay(),
            'balance_kopecks' => 0,
            'bot_paused' => false,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', ['bot_paused' => true])
            ->assertOk()
            ->assertJsonPath('user.bot_paused', true);

        $this->assertTrue($user->fresh()->bot_paused);
    }
}
