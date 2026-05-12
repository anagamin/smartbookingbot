<?php

namespace Tests\Feature;

use App\DataTransferObjects\AiIntentResult;
use App\Models\Appointment;
use App\Models\Dialog;
use App\Models\DialogSession;
use App\Models\Message;
use App\Models\Service;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\ConversationHandler;
use App\Services\GptunnelClient;
use App\Services\VkApiService;
use Carbon\Carbon;
use Mockery;
use Tests\DatabaseTestCase;

class ConversationHandlerBookingServiceTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeAiBookingConfirm(?string $service = null, array $services = []): AiIntentResult
    {
        $list = $services !== [] ? $services : ($service !== null ? [$service] : []);

        return new AiIntentResult(
            intent: 'booking_confirm',
            confidence: 0.95,
            service: $list[0] ?? null,
            services: $list,
            date: '2026-06-05',
            dateEnd: null,
            time: '10:00',
            reply: '',
            needsOwner: false,
        );
    }

    private function makeAiBookingCancel(?string $date = null, ?string $time = null, bool $needsOwner = false): AiIntentResult
    {
        return new AiIntentResult(
            intent: 'booking_cancel',
            confidence: 1,
            service: null,
            services: [],
            date: $date,
            dateEnd: null,
            time: $time,
            reply: '',
            needsOwner: $needsOwner,
        );
    }

    private function bindMocks(AiIntentResult $ai): ConversationHandler
    {
        $gpt = Mockery::mock(GptunnelClient::class);
        $gpt->shouldReceive('classifyMessage')->once()->andReturn($ai);
        $this->app->instance(GptunnelClient::class, $gpt);
        $vk = Mockery::mock(VkApiService::class);
        $vk->shouldReceive('sendGroupMessage')->once();
        $this->app->instance(VkApiService::class, $vk);

        return $this->app->make(ConversationHandler::class);
    }

    private function seedOwnerWithSchedule(User $user): void
    {
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            WorkingHour::query()->create([
                'user_id' => $user->id,
                'weekday' => $weekday,
                'opens_at' => '09:00:00',
                'closes_at' => '18:00:00',
            ]);
        }
    }

    public function test_booking_uses_service_from_session_text_and_duration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        $user = User::factory()->create();
        $this->seedOwnerWithSchedule($user);

        $manicure = Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Маникюр',
            'duration_minutes' => 90,
            'price_kopecks' => 1000,
            'is_active' => true,
        ]);
        Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Педикюр',
            'duration_minutes' => 60,
            'price_kopecks' => 2000,
            'is_active' => true,
        ]);

        $vk = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_VK_GROUP,
            'provider_user_id' => 'g1',
            'access_token' => 'token',
        ]);

        $dialog = Dialog::query()->create([
            'user_id' => $user->id,
            'social_account_id' => $vk->id,
            'external_client_id' => '100',
            'client_name' => 'Клиент',
        ]);

        $session = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_OPEN,
            'started_at' => now(),
        ]);

        Message::query()->create([
            'dialog_id' => $dialog->id,
            'dialog_session_id' => $session->id,
            'direction' => Message::DIRECTION_INBOUND,
            'text' => 'Хочу маникюр на пятницу в 10:00',
        ]);

        $handler = $this->bindMocks($this->makeAiBookingConfirm(null));
        $handler->handleInbound($user, $vk, $dialog, $session, 'Да, подтверждаю это время', 1);

        $appointment = Appointment::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($appointment);
        $this->assertSame($manicure->id, $appointment->service_id);
        $this->assertTrue($appointment->starts_at->eq(Carbon::parse('2026-06-05 10:00:00')));
        $this->assertTrue($appointment->ends_at->eq(Carbon::parse('2026-06-05 11:30:00')));
    }

    public function test_booking_without_resolvable_service_does_not_create_appointment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        $user = User::factory()->create();
        $this->seedOwnerWithSchedule($user);

        Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 1000,
            'is_active' => true,
        ]);
        Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Педикюр',
            'duration_minutes' => 90,
            'price_kopecks' => 2000,
            'is_active' => true,
        ]);

        $vk = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_VK_GROUP,
            'provider_user_id' => 'g1',
            'access_token' => 'token',
        ]);

        $dialog = Dialog::query()->create([
            'user_id' => $user->id,
            'social_account_id' => $vk->id,
            'external_client_id' => '100',
            'client_name' => 'Клиент',
        ]);

        $session = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_OPEN,
            'started_at' => now(),
        ]);

        Message::query()->create([
            'dialog_id' => $dialog->id,
            'dialog_session_id' => $session->id,
            'direction' => Message::DIRECTION_INBOUND,
            'text' => 'Есть места на пятницу?',
        ]);

        $handler = $this->bindMocks($this->makeAiBookingConfirm(null));
        $handler->handleInbound($user, $vk, $dialog, $session, 'Подтверждаю 5 июня в 10:00', 1);

        $this->assertSame(0, Appointment::query()->where('user_id', $user->id)->count());
    }

    public function test_single_active_service_booking_without_ai_service_field(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        $user = User::factory()->create();
        $this->seedOwnerWithSchedule($user);

        $only = Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Стрижка',
            'duration_minutes' => 45,
            'price_kopecks' => 500,
            'is_active' => true,
        ]);
        Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Услуга архив',
            'duration_minutes' => 60,
            'price_kopecks' => 0,
            'is_active' => false,
        ]);

        $vk = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_VK_GROUP,
            'provider_user_id' => 'g1',
            'access_token' => 'token',
        ]);

        $dialog = Dialog::query()->create([
            'user_id' => $user->id,
            'social_account_id' => $vk->id,
            'external_client_id' => '100',
            'client_name' => 'Клиент',
        ]);

        $session = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_OPEN,
            'started_at' => now(),
        ]);

        $handler = $this->bindMocks($this->makeAiBookingConfirm(null));
        $handler->handleInbound($user, $vk, $dialog, $session, 'Подтверждаю 5 июня в 10:00', 1);

        $appointment = Appointment::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($appointment);
        $this->assertSame($only->id, $appointment->service_id);
        $this->assertTrue($appointment->ends_at->eq(Carbon::parse('2026-06-05 10:45:00')));
    }

    public function test_booking_combo_from_dialog_sums_duration_and_stores_extra_services(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        $user = User::factory()->create();
        $this->seedOwnerWithSchedule($user);

        $manicure = Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Маникюр',
            'duration_minutes' => 90,
            'price_kopecks' => 1000,
            'is_active' => true,
        ]);
        $pedicure = Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Педикюр',
            'duration_minutes' => 60,
            'price_kopecks' => 2000,
            'is_active' => true,
        ]);

        $vk = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_VK_GROUP,
            'provider_user_id' => 'g1',
            'access_token' => 'token',
        ]);

        $dialog = Dialog::query()->create([
            'user_id' => $user->id,
            'social_account_id' => $vk->id,
            'external_client_id' => '100',
            'client_name' => 'Клиент',
        ]);

        $session = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_OPEN,
            'started_at' => now(),
        ]);

        Message::query()->create([
            'dialog_id' => $dialog->id,
            'dialog_session_id' => $session->id,
            'direction' => Message::DIRECTION_INBOUND,
            'text' => 'Нужны маникюр и педикюр на пятницу в 10:00',
        ]);

        $handler = $this->bindMocks($this->makeAiBookingConfirm(null));
        $handler->handleInbound($user, $vk, $dialog, $session, 'Да, подтверждаю это время', 1);

        $appointment = Appointment::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($appointment);
        $this->assertSame($manicure->id, $appointment->service_id);
        $this->assertSame([$pedicure->id], $appointment->extra_service_ids ?? []);
        $this->assertTrue($appointment->starts_at->eq(Carbon::parse('2026-06-05 10:00:00')));
        $this->assertTrue($appointment->ends_at->eq(Carbon::parse('2026-06-05 12:30:00')));
        $this->assertSame(3000, (int) $appointment->price_kopecks);
    }

    public function test_cancel_confirmed_appointment_in_current_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-12 10:00:00'));

        $user = User::factory()->create();
        $this->seedOwnerWithSchedule($user);

        $service = Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 1000,
            'is_active' => true,
        ]);

        $vk = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_VK_GROUP,
            'provider_user_id' => 'g1',
            'access_token' => 'token',
        ]);

        $dialog = Dialog::query()->create([
            'user_id' => $user->id,
            'social_account_id' => $vk->id,
            'external_client_id' => '100',
            'client_name' => 'Клиент',
        ]);

        $session = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_OPEN,
            'started_at' => now(),
        ]);

        $appointment = Appointment::query()->create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'dialog_session_id' => $session->id,
            'client_name' => 'Клиент',
            'starts_at' => Carbon::parse('2026-05-20 14:00:00'),
            'ends_at' => Carbon::parse('2026-05-20 15:00:00'),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $handler = $this->bindMocks($this->makeAiBookingCancel(needsOwner: true));
        $handler->handleInbound($user, $vk, $dialog, $session, 'отмените мою запись', 1);

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_CANCELLED, $appointment->status);
    }

    public function test_cancel_ambiguous_when_multiple_future_appointments_for_dialog(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-12 10:00:00'));

        $user = User::factory()->create();
        $this->seedOwnerWithSchedule($user);

        $service = Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 1000,
            'is_active' => true,
        ]);

        $vk = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_VK_GROUP,
            'provider_user_id' => 'g1',
            'access_token' => 'token',
        ]);

        $dialog = Dialog::query()->create([
            'user_id' => $user->id,
            'social_account_id' => $vk->id,
            'external_client_id' => '100',
            'client_name' => 'Клиент',
        ]);

        $session1 = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_CLOSED,
            'started_at' => now()->subDays(2),
            'closed_at' => now()->subDay(),
        ]);
        $session2 = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_CLOSED,
            'started_at' => now()->subDay(),
            'closed_at' => now()->subHours(12),
        ]);
        $session3 = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_OPEN,
            'started_at' => now(),
        ]);

        Appointment::query()->create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'dialog_session_id' => $session1->id,
            'client_name' => 'Клиент',
            'starts_at' => Carbon::parse('2026-05-20 10:00:00'),
            'ends_at' => Carbon::parse('2026-05-20 11:00:00'),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        Appointment::query()->create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'dialog_session_id' => $session2->id,
            'client_name' => 'Клиент',
            'starts_at' => Carbon::parse('2026-05-25 10:00:00'),
            'ends_at' => Carbon::parse('2026-05-25 11:00:00'),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $handler = $this->bindMocks($this->makeAiBookingCancel(needsOwner: true));
        $handler->handleInbound($user, $vk, $dialog, $session3, 'отмените мою запись', 1);

        $this->assertSame(2, Appointment::query()->where('user_id', $user->id)->where('status', Appointment::STATUS_CONFIRMED)->count());
    }

    public function test_cancel_by_ai_date_when_multiple_appointments_in_dialog(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-12 10:00:00'));

        $user = User::factory()->create();
        $this->seedOwnerWithSchedule($user);

        $service = Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 1000,
            'is_active' => true,
        ]);

        $vk = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_VK_GROUP,
            'provider_user_id' => 'g1',
            'access_token' => 'token',
        ]);

        $dialog = Dialog::query()->create([
            'user_id' => $user->id,
            'social_account_id' => $vk->id,
            'external_client_id' => '100',
            'client_name' => 'Клиент',
        ]);

        $session1 = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_CLOSED,
            'started_at' => now()->subDays(2),
        ]);
        $session2 = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_CLOSED,
            'started_at' => now()->subDay(),
        ]);
        $session3 = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_OPEN,
            'started_at' => now(),
        ]);

        Appointment::query()->create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'dialog_session_id' => $session1->id,
            'client_name' => 'Клиент',
            'starts_at' => Carbon::parse('2026-05-20 10:00:00'),
            'ends_at' => Carbon::parse('2026-05-20 11:00:00'),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $later = Appointment::query()->create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'dialog_session_id' => $session2->id,
            'client_name' => 'Клиент',
            'starts_at' => Carbon::parse('2026-05-25 10:00:00'),
            'ends_at' => Carbon::parse('2026-05-25 11:00:00'),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $handler = $this->bindMocks($this->makeAiBookingCancel(date: '2026-05-25'));
        $handler->handleInbound($user, $vk, $dialog, $session3, 'отмените запись на 25 мая', 1);

        $later->refresh();
        $this->assertSame(Appointment::STATUS_CANCELLED, $later->status);
        $this->assertSame(1, Appointment::query()->where('user_id', $user->id)->where('status', Appointment::STATUS_CONFIRMED)->count());
    }

    public function test_cancel_manual_appointment_matches_dialog_client_name(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-12 10:00:00'));

        $user = User::factory()->create();
        $this->seedOwnerWithSchedule($user);

        $service = Service::query()->create([
            'user_id' => $user->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 1000,
            'is_active' => true,
        ]);

        $vk = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_VK_GROUP,
            'provider_user_id' => 'g1',
            'access_token' => 'token',
        ]);

        $dialog = Dialog::query()->create([
            'user_id' => $user->id,
            'social_account_id' => $vk->id,
            'external_client_id' => '100',
            'client_name' => 'Виктория Степанова',
        ]);

        $session = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_OPEN,
            'started_at' => now(),
        ]);

        $appointment = Appointment::query()->create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'dialog_session_id' => null,
            'client_name' => 'Виктория Степанова',
            'starts_at' => Carbon::parse('2026-05-20 14:00:00'),
            'ends_at' => Carbon::parse('2026-05-20 15:00:00'),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $handler = $this->bindMocks($this->makeAiBookingCancel());
        $handler->handleInbound($user, $vk, $dialog, $session, 'отмените мою запись', 1);

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_CANCELLED, $appointment->status);
    }
}
