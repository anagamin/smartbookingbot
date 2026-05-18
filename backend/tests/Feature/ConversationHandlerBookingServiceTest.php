<?php

namespace Tests\Feature;

use App\DataTransferObjects\AiIntentResult;
use App\Models\Appointment;
use App\Models\Dialog;
use App\Models\DialogSession;
use App\Models\Master;
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

    private function makeAiAvailabilityRequest(string $date, array $services = []): AiIntentResult
    {
        return new AiIntentResult(
            intent: 'availability_request',
            confidence: 0.9,
            service: $services[0] ?? null,
            services: $services,
            date: $date,
            dateEnd: null,
            time: null,
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

    public function test_availability_uses_last_explicit_service_message_not_old_combo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-12 10:00:00'));

        $user = User::factory()->create();
        WorkingHour::query()->create([
            'user_id' => $user->id,
            'weekday' => 3,
            'opens_at' => '10:00:00',
            'closes_at' => '19:00:00',
        ]);

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
            'duration_minutes' => 120,
            'price_kopecks' => 2000,
            'is_active' => true,
        ]);

        $vkAcc = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_VK_GROUP,
            'provider_user_id' => 'g1',
            'access_token' => 'token',
        ]);

        $dialog = Dialog::query()->create([
            'user_id' => $user->id,
            'social_account_id' => $vkAcc->id,
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
            'text' => 'Запишите маникюр и педикюр на следующей неделе',
        ]);
        Message::query()->create([
            'dialog_id' => $dialog->id,
            'dialog_session_id' => $session->id,
            'direction' => Message::DIRECTION_INBOUND,
            'text' => 'Хочу только педикюр на завтра, подскажите окна',
        ]);

        $captured = '';
        $gpt = Mockery::mock(GptunnelClient::class);
        $gpt->shouldReceive('classifyMessage')->once()->andReturn($this->makeAiAvailabilityRequest('2026-05-13'));
        $this->app->instance(GptunnelClient::class, $gpt);
        $vk = Mockery::mock(VkApiService::class);
        $vk->shouldReceive('sendGroupMessage')->once()->with(
            Mockery::type('string'),
            Mockery::type('int'),
            Mockery::on(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            }),
        );
        $this->app->instance(VkApiService::class, $vk);

        $handler = $this->app->make(ConversationHandler::class);
        $handler->handleInbound($user, $vkAcc, $dialog, $session, 'А какие свободные слоты на завтра?', 1);

        $this->assertStringContainsString('17:00', $captured, 'Последний старт при педикюре 2 ч до закрытия 19:00 — 17:00');
        $this->assertStringContainsString('10:00', $captured);
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

    public function test_cancel_rewrites_messages_to_appointment_dialog_session(): void
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

        $bookingSession = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_CLOSED,
            'started_at' => now()->subDay(),
            'closed_at' => now()->subHours(2),
            'intent' => 'booking',
        ]);

        $appointment = Appointment::query()->create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'dialog_session_id' => $bookingSession->id,
            'client_name' => 'Клиент',
            'starts_at' => Carbon::parse('2026-05-20 14:00:00'),
            'ends_at' => Carbon::parse('2026-05-20 15:00:00'),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $ephemeralSession = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_OPEN,
            'started_at' => now(),
        ]);

        $handler = $this->bindMocks($this->makeAiBookingCancel(needsOwner: true));
        $handler->handleInbound($user, $vk, $dialog, $ephemeralSession, 'отмените мою запись', 1);

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_CANCELLED, $appointment->status);

        $this->assertNull(DialogSession::query()->find($ephemeralSession->id));

        $messages = Message::query()->where('dialog_session_id', $bookingSession->id)->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertSame(Message::DIRECTION_INBOUND, $messages[0]->direction);
        $this->assertSame('отмените мою запись', $messages[0]->text);
        $this->assertSame(Message::DIRECTION_OUTBOUND, $messages[1]->direction);
        $this->assertStringContainsString('отменена', mb_strtolower($messages[1]->text));
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

    public function test_availability_with_named_service_uses_primary_master_schedule_in_solo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-18 10:00:00'));

        $user = User::factory()->create(['business_mode' => 'solo']);
        $primary = $this->primaryMaster($user);
        $extraMaster = Master::query()->create([
            'user_id' => $user->id,
            'name' => 'Без графика',
            'sort_order' => 1,
        ]);

        WorkingHour::query()->create([
            'user_id' => $user->id,
            'master_id' => $primary->id,
            'weekday' => 1,
            'opens_at' => '10:00:00',
            'closes_at' => '18:00:00',
        ]);

        Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $extraMaster->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 1000,
            'is_active' => true,
        ]);

        $vkAcc = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_VK_GROUP,
            'provider_user_id' => 'g1',
            'access_token' => 'token',
        ]);

        $dialog = Dialog::query()->create([
            'user_id' => $user->id,
            'social_account_id' => $vkAcc->id,
            'external_client_id' => '100',
            'client_name' => 'Клиент',
        ]);

        $session = DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'status' => DialogSession::STATUS_OPEN,
            'started_at' => now(),
        ]);

        $captured = '';
        $gpt = Mockery::mock(GptunnelClient::class);
        $gpt->shouldReceive('classifyMessage')->once()->andReturn(
            $this->makeAiAvailabilityRequest('2026-05-18', ['Маникюр']),
        );
        $this->app->instance(GptunnelClient::class, $gpt);
        $vk = Mockery::mock(VkApiService::class);
        $vk->shouldReceive('sendGroupMessage')->once()->with(
            Mockery::type('string'),
            Mockery::type('int'),
            Mockery::on(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            }),
        );
        $this->app->instance(VkApiService::class, $vk);

        $handler = $this->app->make(ConversationHandler::class);
        $handler->handleInbound(
            $user,
            $vkAcc,
            $dialog,
            $session,
            'хочу записаться на маникюр. какие свободные окна есть на ближайшие дни?',
            1,
        );

        $this->assertStringContainsString('Могу предложить', $captured);
        $this->assertStringContainsString('10:00', $captured);
    }

    public function test_booking_confirmation_includes_service_and_salon_master(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-18 10:00:00'));

        $user = User::factory()->create(['business_mode' => 'salon']);
        $master = $this->primaryMaster($user);
        $master->update(['name' => 'Юрий']);

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            WorkingHour::query()->create([
                'user_id' => $user->id,
                'master_id' => $master->id,
                'weekday' => $weekday,
                'opens_at' => '09:00:00',
                'closes_at' => '18:00:00',
            ]);
        }

        Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $master->id,
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

        $captured = '';
        $gpt = Mockery::mock(GptunnelClient::class);
        $gpt->shouldReceive('classifyMessage')->once()->andReturn(new AiIntentResult(
            intent: 'booking_confirm',
            confidence: 0.95,
            service: 'Маникюр',
            services: ['Маникюр'],
            master: null,
            date: '2026-05-19',
            dateEnd: null,
            time: '10:00',
            reply: '',
            needsOwner: false,
        ));
        $this->app->instance(GptunnelClient::class, $gpt);
        $vkMock = Mockery::mock(VkApiService::class);
        $vkMock->shouldReceive('sendGroupMessage')->once()->with(
            Mockery::type('string'),
            Mockery::type('int'),
            Mockery::on(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            }),
        );
        $this->app->instance(VkApiService::class, $vkMock);

        $handler = $this->app->make(ConversationHandler::class);
        $handler->handleInbound($user, $vk, $dialog, $session, 'Давайте 19 мая на 10:00', 1);

        $this->assertStringContainsString('на Маникюр', $captured);
        $this->assertStringContainsString('к Юрий', $captured);
        $this->assertStringContainsString('19 мая', $captured);
    }

    public function test_booking_confirmation_includes_service_without_master_in_solo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        $user = User::factory()->create(['business_mode' => 'solo']);
        $master = $this->primaryMaster($user);
        $master->update(['name' => 'Юрий']);

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            WorkingHour::query()->create([
                'user_id' => $user->id,
                'master_id' => $master->id,
                'weekday' => $weekday,
                'opens_at' => '09:00:00',
                'closes_at' => '18:00:00',
            ]);
        }

        Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $master->id,
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

        $captured = '';
        $gpt = Mockery::mock(GptunnelClient::class);
        $gpt->shouldReceive('classifyMessage')->once()->andReturn(
            $this->makeAiBookingConfirm('Маникюр', ['Маникюр']),
        );
        $this->app->instance(GptunnelClient::class, $gpt);
        $vkMock = Mockery::mock(VkApiService::class);
        $vkMock->shouldReceive('sendGroupMessage')->once()->with(
            Mockery::type('string'),
            Mockery::type('int'),
            Mockery::on(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            }),
        );
        $this->app->instance(VkApiService::class, $vkMock);

        $handler = $this->app->make(ConversationHandler::class);
        $handler->handleInbound($user, $vk, $dialog, $session, 'Давайте 5 июня на 10:00', 1);

        $this->assertStringContainsString('на Маникюр', $captured);
        $this->assertStringNotContainsString('к Юрий', $captured);
    }

    public function test_availability_on_empty_day_states_no_slots_and_suggests_other_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-18 10:00:00'));

        $user = User::factory()->create(['business_mode' => 'solo']);
        $master = $this->primaryMaster($user);

        foreach ([1, 2, 5] as $weekday) {
            WorkingHour::query()->create([
                'user_id' => $user->id,
                'master_id' => $master->id,
                'weekday' => $weekday,
                'opens_at' => '10:00:00',
                'closes_at' => '18:00:00',
            ]);
        }

        Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $master->id,
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

        $captured = '';
        $gpt = Mockery::mock(GptunnelClient::class);
        $gpt->shouldReceive('classifyMessage')->once()->andReturn(
            $this->makeAiAvailabilityRequest('2026-05-21', ['Маникюр']),
        );
        $this->app->instance(GptunnelClient::class, $gpt);
        $vkMock = Mockery::mock(VkApiService::class);
        $vkMock->shouldReceive('sendGroupMessage')->once()->with(
            Mockery::type('string'),
            Mockery::type('int'),
            Mockery::on(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            }),
        );
        $this->app->instance(VkApiService::class, $vkMock);

        $handler = $this->app->make(ConversationHandler::class);
        $handler->handleInbound(
            $user,
            $vk,
            $dialog,
            $session,
            'хочу записаться на маникюр. какие свободные окна есть на 21 число?',
            1,
        );

        $this->assertStringContainsString('21 мая', $captured);
        $this->assertStringContainsString('свободных окон нет', $captured);
        $this->assertStringContainsString('Могу предложить', $captured);
        $this->assertStringNotContainsString('21 мая:', $captured);
    }

    public function test_availability_merges_slots_from_multiple_masters_with_same_service_title(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-18 10:00:00'));

        $user = User::factory()->create(['business_mode' => 'salon']);
        $masterA = $this->primaryMaster($user);
        $masterA->update(['name' => 'Анна']);
        $masterB = Master::query()->create([
            'user_id' => $user->id,
            'name' => 'Ольга',
            'sort_order' => 1,
        ]);

        foreach ([1, 2] as $weekday) {
            WorkingHour::query()->create([
                'user_id' => $user->id,
                'master_id' => $masterA->id,
                'weekday' => $weekday,
                'opens_at' => '10:00:00',
                'closes_at' => '14:00:00',
            ]);
        }
        foreach ([5, 6] as $weekday) {
            WorkingHour::query()->create([
                'user_id' => $user->id,
                'master_id' => $masterB->id,
                'weekday' => $weekday,
                'opens_at' => '10:00:00',
                'closes_at' => '14:00:00',
            ]);
        }

        Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $masterA->id,
            'title' => 'Маникюр',
            'duration_minutes' => 60,
            'price_kopecks' => 1000,
            'is_active' => true,
        ]);
        Service::query()->create([
            'user_id' => $user->id,
            'master_id' => $masterB->id,
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

        $captured = '';
        $gpt = Mockery::mock(GptunnelClient::class);
        $gpt->shouldReceive('classifyMessage')->once()->andReturn(
            $this->makeAiAvailabilityRequest('2026-05-21', ['Маникюр']),
        );
        $this->app->instance(GptunnelClient::class, $gpt);
        $vkMock = Mockery::mock(VkApiService::class);
        $vkMock->shouldReceive('sendGroupMessage')->once()->with(
            Mockery::type('string'),
            Mockery::type('int'),
            Mockery::on(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            }),
        );
        $this->app->instance(VkApiService::class, $vkMock);

        $handler = $this->app->make(ConversationHandler::class);
        $handler->handleInbound(
            $user,
            $vk,
            $dialog,
            $session,
            'хочу записаться на маникюр. какие свободные окна есть на 21 число?',
            1,
        );

        $this->assertStringContainsString('21 мая', $captured);
        $this->assertStringContainsString('свободных окон нет', $captured);
        $this->assertStringContainsString('22 мая', $captured);
        $this->assertStringContainsString('23 мая', $captured);
    }
}
