<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\DatabaseTestCase;

class AuthRegistrationTest extends DatabaseTestCase
{
    public function test_register_returns_token_and_sets_trial(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'sex' => 'female',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'trial_ends_at']]);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
        $user = User::query()->where('email', 'test@example.com')->first();
        $this->assertNotNull($user?->trial_ends_at);
        $this->assertNotNull($user?->next_billing_at);
    }
}
