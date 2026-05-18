<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Support\BookingSlug;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\DatabaseTestCase;

class PublicBookingTest extends DatabaseTestCase
{
    public function test_profile_booking_slug_must_be_unique(): void
    {
        User::factory()->create(['booking_slug' => 'ivan']);
        $user = User::factory()->create(['booking_slug' => 'other']);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/profile', ['booking_slug' => 'ivan']);

        $response->assertStatus(422);
    }

    public function test_public_booking_page_and_appointment_creation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-19 08:00:00', 'Europe/Moscow'));

        $user = User::factory()->create(['booking_slug' => 'maria', 'name' => 'Мария']);
        $master = $this->primaryMaster($user);
        WorkingHour::query()->create([
            'user_id' => $user->id,
            'master_id' => $master->id,
            'weekday' => 1,
            'opens_at' => '10:00',
            'closes_at' => '18:00',
        ]);
        $svc = Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $master->id,
            'title' => 'Маникюр',
            'price_kopecks' => 150_000,
            'duration_minutes' => 60,
            'is_active' => true,
        ]);

        $show = $this->getJson('/api/public/book/maria');
        $show->assertOk()
            ->assertJsonPath('owner_name', 'Мария')
            ->assertJsonCount(1, 'services');

        $slots = $this->getJson('/api/public/book/maria/slots?service_ids[]='.$svc->id);
        $slots->assertOk()
            ->assertJsonPath('duration_minutes', 60);
        $days = $slots->json('days');
        $this->assertNotEmpty($days);
        $startsAt = $days[0]['slots'][0]['starts_at'];

        $book = $this->postJson('/api/public/book/maria/appointments', [
            'client_name' => 'Анна',
            'service_ids' => [$svc->id],
            'starts_at' => $startsAt,
            'comment' => 'Снятие гель-лака',
        ]);
        $book->assertCreated();

        $appointment = Appointment::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($appointment);
        $this->assertSame('Анна', $appointment->client_name);
        $this->assertSame('Снятие гель-лака', $appointment->chat_excerpt);
        $this->assertSame(150_000, $appointment->price_kopecks);
        $this->assertSame($master->id, $appointment->master_id);

        Carbon::setTestNow();
    }

    public function test_booking_slug_normalization(): void
    {
        $this->assertSame('ivan-petrov', BookingSlug::normalize('Иван Петров'));
        $this->assertTrue(BookingSlug::isValid('my-salon'));
        $this->assertFalse(BookingSlug::isValid('api'));
    }
}
