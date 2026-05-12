<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use Tests\DatabaseTestCase;

class ContactMessageTest extends DatabaseTestCase
{
    public function test_store_creates_message_and_returns_ok(): void
    {
        $response = $this->postJson('/api/contact-messages', [
            'message_type' => 'bug',
            'body' => 'Текст обращения достаточной длины.',
        ]);

        $response->assertCreated()
            ->assertJson(['message' => 'Запрос отправлен.']);

        $this->assertDatabaseHas('contact_messages', [
            'message_type' => 'bug',
            'body' => 'Текст обращения достаточной длины.',
        ]);
    }

    public function test_index_returns_messages_newest_first(): void
    {
        ContactMessage::query()->create([
            'message_type' => 'improvement',
            'body' => 'Первое',
            'response' => null,
        ]);
        ContactMessage::query()->where('body', 'Первое')->update(['created_at' => now()->subHour()]);

        ContactMessage::query()->create([
            'message_type' => 'bug',
            'body' => 'Второе',
            'response' => 'Готово',
        ]);

        $response = $this->getJson('/api/contact-messages');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('Второе', $data[0]['body']);
        $this->assertSame('Первое', $data[1]['body']);
        $this->assertNull($data[1]['response']);
    }

    public function test_store_validation_rejects_invalid_type(): void
    {
        $response = $this->postJson('/api/contact-messages', [
            'message_type' => 'spam',
            'body' => 'Текст',
        ]);

        $response->assertUnprocessable();
    }
}
