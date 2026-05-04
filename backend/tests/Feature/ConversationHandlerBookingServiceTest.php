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

    private function makeAiBookingConfirm(?string $service = null): AiIntentResult
    {
        return new AiIntentResult(
            intent: 'booking_confirm',
            confidence: 0.95,
            service: $service,
            date: '2026-06-05',
            dateEnd: null,
            time: '10:00',
            reply: '',
            needsOwner: false,
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
}
