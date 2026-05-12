<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\DatabaseTestCase;

class ContactMessageTest extends DatabaseTestCase
{
    public function test_guest_cannot_list_or_create(): void
    {
        $this->getJson('/api/contact-messages')->assertUnauthorized();
        $this->postJson('/api/contact-messages', [
            'message_type' => 'bug',
            'body' => 'Текст обращения достаточной длины.',
        ])->assertUnauthorized();
    }

    public function test_store_creates_message_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/contact-messages', [
            'message_type' => 'bug',
            'body' => 'Текст обращения достаточной длины.',
        ]);

        $response->assertCreated()
            ->assertJson(['message' => 'Запрос отправлен.']);

        $this->assertDatabaseHas('contact_messages', [
            'user_id' => $user->id,
            'message_type' => 'bug',
            'body' => 'Текст обращения достаточной длины.',
        ]);
    }

    public function test_index_returns_only_current_user_messages_newest_first(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        ContactMessage::query()->create([
            'user_id' => $alice->id,
            'message_type' => 'improvement',
            'body' => 'Алиса старое',
            'response' => null,
        ]);
        ContactMessage::query()->where('body', 'Алиса старое')->update(['created_at' => now()->subHour()]);

        ContactMessage::query()->create([
            'user_id' => $alice->id,
            'message_type' => 'bug',
            'body' => 'Алиса новое',
            'response' => 'Ок',
        ]);

        ContactMessage::query()->create([
            'user_id' => $bob->id,
            'message_type' => 'bug',
            'body' => 'Секрет Боба',
            'response' => null,
        ]);

        Sanctum::actingAs($alice);
        $response = $this->getJson('/api/contact-messages');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('Алиса новое', $data[0]['body']);
        $this->assertSame('Алиса старое', $data[1]['body']);
        $this->assertNull($data[1]['response']);
    }

    public function test_store_validation_rejects_invalid_type(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/contact-messages', [
            'message_type' => 'spam',
            'body' => 'Текст',
        ]);

        $response->assertUnprocessable();
    }
}
