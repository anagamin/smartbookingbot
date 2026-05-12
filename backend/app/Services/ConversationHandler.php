<?php

namespace App\Services;

use App\DataTransferObjects\AiIntentResult;
use App\Models\Appointment;
use App\Models\Dialog;
use App\Models\DialogSession;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Service;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class ConversationHandler
{
    public function __construct(
        private readonly GptunnelClient $gptunnel,
        private readonly SlotAvailabilityService $slots,
        private readonly VkApiService $vk,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function handleInbound(
        User $owner,
        SocialAccount $vkGroupAccount,
        Dialog $dialog,
        DialogSession $session,
        string $inboundText,
        int $peerId,
    ): void {
        if (! $owner->canRunBot()) {
            $this->activityLogger->log($owner, 'bot_skipped', 'Бот на паузе или недостаточно средств.', [
                'dialog_id' => $dialog->id,
            ]);

            return;
        }

        Message::query()->create([
            'dialog_id' => $dialog->id,
            'dialog_session_id' => $session->id,
            'direction' => Message::DIRECTION_INBOUND,
            'text' => $inboundText,
        ]);
        $dialog->update(['last_message_at' => now()]);

        $systemPrompt = $this->buildSystemPrompt();
        $userPayload = $this->buildUserPayload($owner, $dialog, $session, $inboundText);

        try {
            $ai = $this->gptunnel->classifyMessage($systemPrompt, $userPayload);
        } catch (Throwable $e) {
            $this->notifyOwner($owner, 'Ошибка AI', $e->getMessage());
            $this->activityLogger->log($owner, 'ai_error', $e->getMessage(), ['dialog_id' => $dialog->id]);

            return;
        }

        $this->activityLogger->log($owner, 'ai_intent', 'Классификация: '.$ai->intent, [
            'dialog_id' => $dialog->id,
            'intent' => $ai->intent,
            'confidence' => $ai->confidence,
        ]);

        $reply = $this->executeIntent($owner, $vkGroupAccount, $dialog, $session, $peerId, $ai, $inboundText);
        if ($reply !== null && $reply !== '') {
            $this->vk->sendGroupMessage($vkGroupAccount->access_token, $peerId, $reply);
            Message::query()->create([
                'dialog_id' => $dialog->id,
                'dialog_session_id' => $session->id,
                'direction' => Message::DIRECTION_OUTBOUND,
                'text' => $reply,
            ]);
        }
    }

    private function executeIntent(
        User $owner,
        SocialAccount $vkGroupAccount,
        Dialog $dialog,
        DialogSession $session,
        int $peerId,
        AiIntentResult $ai,
        string $inboundText,
    ): ?string {
        return match ($ai->intent) {
            'informational' => $this->handleInformational($owner, $ai),
            'availability_request' => $this->handleAvailability($owner, $session, $ai),
            'booking_confirm' => $this->handleBookingConfirm($owner, $dialog, $session, $ai),
            'booking_cancel' => $this->handleBookingCancel($owner, $dialog, $session, $ai),
            'chit_chat' => $ai->reply !== '' ? $ai->reply : 'Пожалуйста! Если понадобится запись — напишите.',
            default => $this->handleOther($owner, $ai, $inboundText),
        };
    }

    private function handleInformational(User $owner, AiIntentResult $ai): ?string
    {
        if ($ai->needsOwner) {
            $this->notifyOwner($owner, 'Нужен ответ мастеру', 'Бот не уверен в ответе: '.$ai->reply);

            return 'Передала вопрос мастеру, он ответит в ближайшее время.';
        }

        return $ai->reply !== '' ? $ai->reply : null;
    }

    private function handleAvailability(User $owner, DialogSession $session, AiIntentResult $ai): string
    {
        $resolved = $this->resolveServicesFromContext($owner, $session, $ai);
        $duration = $resolved->isEmpty()
            ? 60
            : max(1, $resolved->sum(fn (Service $s) => (int) $s->duration_minutes));
        $singleService = $resolved->count() === 1 ? $resolved->first() : null;
        $durationOverride = $resolved->count() > 1 ? $duration : null;

        $date = $this->normalizeAiDate($ai->date);
        $time = $ai->time !== null && $ai->time !== '' ? $ai->time : null;

        if ($date !== null && $time !== null) {
            $start = Carbon::parse($date.' '.$time);
            $end = $start->copy()->addMinutes($duration);
            if ($end->lte(now())) {
                return 'Это время уже прошло. Напишите актуальную дату и время.';
            }
            if (! $this->slots->isIntervalWithinWorkingHours($owner, $start, $end)) {
                return 'В это время по графику мастер не принимает. Могу прислать свободные окна в рабочие часы — напишите день недели или «сегодня»/«завтра».';
            }
            if (! $this->slots->isSlotFree($owner, $start, $end)) {
                return 'На '.$start->translatedFormat('j F, H:i').' уже есть запись. Могу предложить другие слоты — напишите удобный день.';
            }

            return 'На '.$start->translatedFormat('j F, H:i').' свободно. Если хотите записаться — напишите, что подтверждаете это время.';
        }

        $rangeEnd = $this->normalizeAiDate($ai->dateEnd) ?? $date;
        if ($date !== null && $rangeEnd !== null && Carbon::parse($rangeEnd)->lt(Carbon::parse($date))) {
            $rangeEnd = $date;
        }

        $suggestions = $date !== null
            ? $this->slots->suggestSlotsInDateRange(
                $owner,
                $singleService,
                Carbon::parse($date),
                Carbon::parse($rangeEnd ?? $date),
                80,
                $durationOverride,
            )
            : $this->slots->suggestSlots($owner, $singleService, 14, 24, $durationOverride);

        if ($suggestions === []) {
            return 'Свободных окон в ближайшие дни не нашла (проверьте график работы в кабинете или напишите желаемый день — уточню у мастера).';
        }

        return $this->formatAvailabilitySuggestions($suggestions);
    }

    /**
     * @param  list<array{start: Carbon, end: Carbon}>  $suggestions
     */
    private function formatAvailabilitySuggestions(array $suggestions): string
    {
        $byDay = [];
        foreach ($suggestions as $s) {
            $key = $s['start']->toDateString();
            $byDay[$key][] = $s['start']->format('H:i');
        }
        ksort($byDay);
        $parts = [];
        foreach ($byDay as $dateStr => $times) {
            $parts[] = Carbon::parse($dateStr)->locale('ru')->translatedFormat('j F').': '.implode(', ', $times);
        }

        return 'Могу предложить: '.implode('; ', $parts).'. Какой вариант вам подходит?';
    }

    private function handleBookingConfirm(
        User $owner,
        Dialog $dialog,
        DialogSession $session,
        AiIntentResult $ai,
    ): ?string {
        $resolvedDate = $this->normalizeAiDate($ai->date);
        if ($resolvedDate === null || $ai->time === null || $ai->time === '') {
            $this->notifyOwner($owner, 'Неполная запись', 'Клиент подтвердил запись, но нет даты/времени в AI-ответе.');

            return 'Уточните, пожалуйста, дату и время записи одним сообщением.';
        }
        $services = $this->resolveServicesFromContext($owner, $session, $ai);
        if ($services->isEmpty()) {
            $active = Service::query()
                ->where('user_id', $owner->id)
                ->where('is_active', true)
                ->orderBy('title')
                ->get();
            if ($active->isEmpty()) {
                $this->notifyOwner($owner, 'Запись через бота', 'Клиент подтвердил время, но у вас нет активных услуг в кабинете.');

                return 'Сейчас запись через бот недоступна: в кабинете не настроены услуги. Мастер ответит вам в этом чате.';
            }

            return $this->buildAskServiceMessage($active);
        }
        $duration = max(1, $services->sum(fn (Service $s) => (int) $s->duration_minutes));
        $start = Carbon::parse($resolvedDate.' '.$ai->time);
        $end = $start->copy()->addMinutes($duration);
        if (! $this->slots->isIntervalWithinWorkingHours($owner, $start, $end)) {
            return 'Это время вне графика работы. Выберите другое время в рабочие часы или напишите день — пришлю свободные окна.';
        }
        if (! $this->slots->isSlotFree($owner, $start, $end)) {
            return 'Это время уже занято. Предлагаю другое — напишите удобный день, я пришлю свободные слоты.';
        }
        $price = $services->sum(fn (Service $s) => (int) ($s->price_kopecks ?? 0));
        $orderedIds = $services->pluck('id')->values()->all();
        $primaryId = array_shift($orderedIds);
        $appointment = Appointment::query()->create([
            'user_id' => $owner->id,
            'service_id' => $primaryId,
            'extra_service_ids' => $orderedIds === [] ? null : $orderedIds,
            'dialog_session_id' => $session->id,
            'client_name' => $dialog->client_name ?? 'Клиент VK',
            'starts_at' => $start,
            'ends_at' => $end,
            'price_kopecks' => $price,
            'chat_excerpt' => Str::limit($ai->reply, 500),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $session->update(['status' => DialogSession::STATUS_CLOSED, 'closed_at' => now(), 'intent' => 'booking']);
        $this->activityLogger->log($owner, 'appointment_created', 'Запись #'.$appointment->id, [
            'appointment_id' => $appointment->id,
        ]);

        return 'Запись зафиксирована на '.$start->translatedFormat('j F Y, H:i').'. Ждём вас!';
    }

    private function handleBookingCancel(
        User $owner,
        Dialog $dialog,
        DialogSession $session,
        AiIntentResult $ai,
    ): string {
        $appointment = Appointment::query()
            ->where('user_id', $owner->id)
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->whereHas('dialogSession', fn ($q) => $q->where('dialog_id', $dialog->id))
            ->orderByDesc('starts_at')
            ->first();
        if (! $appointment) {
            $this->notifyOwner($owner, 'Отмена записи', 'Клиент просит отмену, но активной записи не найдено.');

            return 'Не нашла активную запись на ваше имя. Если запись была — мастер свяжется с вами.';
        }
        $appointment->update(['status' => Appointment::STATUS_CANCELLED]);
        $session->update(['status' => DialogSession::STATUS_CLOSED, 'closed_at' => now(), 'intent' => 'cancel']);
        $this->activityLogger->log($owner, 'appointment_cancelled', 'Отмена #'.$appointment->id, [
            'appointment_id' => $appointment->id,
        ]);

        return 'Запись отменена. Будем рады видеть вас в другой раз!';
    }

    /**
     * Активные услуги для записи/слотов: из полей AI (несколько названий), из текста сессии или одна активная в кабинете.
     *
     * @return Collection<int, Service>
     */
    private function resolveServicesFromContext(User $owner, DialogSession $session, AiIntentResult $ai): Collection
    {
        $active = Service::query()
            ->where('user_id', $owner->id)
            ->where('is_active', true)
            ->get()
            ->sortByDesc(fn (Service $s) => mb_strlen($s->title))
            ->values();

        if ($active->isEmpty()) {
            return collect();
        }

        if ($active->count() === 1) {
            return collect([$active->first()]);
        }

        $resolved = collect();
        foreach ($ai->services as $title) {
            $s = $this->slots->findServiceByTitle($owner, $title);
            if ($s !== null) {
                $resolved->push($s);
            }
        }
        $resolved = $resolved->unique('id')->values();
        if ($resolved->isNotEmpty()) {
            return $resolved;
        }

        $corpus = Message::query()
            ->where('dialog_session_id', $session->id)
            ->orderBy('id')
            ->pluck('text')
            ->map(fn (string $t) => mb_strtolower($t))
            ->implode("\n");

        if ($corpus === '') {
            return collect();
        }

        return $this->slots->findServicesInDialogText($owner, $corpus);
    }

    /**
     * @param  Collection<int, Service>  $active
     */
    private function buildAskServiceMessage(Collection $active): string
    {
        $parts = $active->map(
            fn (Service $s) => $s->title.' ('.$this->formatDurationHuman((int) $s->duration_minutes).')',
        )->implode('; ');

        return 'Чтобы зафиксировать запись, укажите услугу или несколько — от этого зависит длительность. Доступно: '.$parts.'. Например: «подтверждаю, маникюр и педикюр, это время» или перечислите услуги через запятую.';
    }

    private function formatDurationHuman(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' мин';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $chunks = [];
        if ($h > 0) {
            $chunks[] = $h.' ч';
        }
        if ($m > 0) {
            $chunks[] = $m.' мин';
        }

        return $chunks === [] ? $minutes.' мин' : implode(' ', $chunks);
    }

    private function handleOther(User $owner, AiIntentResult $ai, string $inboundText): string
    {
        $this->notifyOwner($owner, 'Сообщение без автоответа', Str::limit($inboundText, 400));

        return 'Передала сообщение мастеру — он ответит лично.';
    }

    private function notifyOwner(User $owner, string $title, string $body): void
    {
        Notification::query()->create([
            'user_id' => $owner->id,
            'title' => $title,
            'body' => $body,
        ]);
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты классификатор сообщений клиента мастера (маникюр, массаж и т.п.). Ответь СТРОГО одним JSON-объектом без markdown, поля:
- intent: один из: informational, availability_request, booking_confirm, booking_cancel, chit_chat, other
- confidence: число 0..1
- service: string или null (одна услуга или краткое описание; для обратной совместимости)
- services: массив строк — названия услуг из массива services во входных данных (можно 0, 1 или несколько). Если клиент хочет несколько услуг подряд в один визит — перечисли каждую отдельной строкой в этом массиве (например ["Маникюр","Педикюр"]). Если одна услуга — либо одна строка в services, либо только service.
- date: "Y-m-d" или null
- date_end: "Y-m-d" или null — последний день диапазона включительно, если речь о нескольких днях; иначе null (или то же, что date)
- time: "H:i" или null
- reply: короткий черновик ответа клиенту на русском (для chit_chat/informational), иначе можно пустую строку
- needs_owner: boolean — true если нужен мастер без данных из контекста

Правила:
- informational: вопрос о цене, услуге, адресе из контекста
- availability_request: когда можно, свободные слоты; если визит из нескольких услуг — заполни services всеми (длительность для слотов = сумма duration_minutes). Если клиент спрашивает про конкретную дату/время («завтра в 16:00», «5 мая в 10:30») — обязательно заполни поля date (Y-m-d) и time (H:i), вычислив дату от reference.server_today (завтра = server_today + 1 день). Если указан только день или период без времени («5 мая», «в выходные», «на следующей неделе») — time=null; для одного дня date_end=null; для диапазона заполни date (первый день) и date_end (последний). «Выходные» — ближайшая суббота–воскресенье от server_today (если сегодня суббота/воскресенье — текущие выходные). «На следующей неделе» — календарная неделя после той, в которой server_today (пн–вс этой «следующей» недели).
- booking_confirm: явное согласие на конкретные дату и время; заполни services списком услуг визита (одна или несколько), каждая должна узнаваться по массиву services из контекста. Если несколько услуг — суммарная длительность = сумма duration_minutes. Если нельзя сопоставить ни одну — services: [] и service: null.
- booking_cancel: отмена записи
- chit_chat: спасибо, ок, до связи
- other: всё остальное

Важно для date: год всегда бери из reference.current_year, если клиент год не указал явно. Не используй прошлые годы (2024 и т.д.), если речь о ближайшей записи.
PROMPT;
    }

    private function buildUserPayload(User $owner, Dialog $dialog, DialogSession $session, string $inboundText): string
    {
        $services = Service::query()->where('user_id', $owner->id)->where('is_active', true)->get();
        $servicesJson = $services->map(fn (Service $s) => [
            'title' => $s->title,
            'price_kopecks' => $s->price_kopecks,
            'duration_minutes' => $s->duration_minutes,
            'description' => $s->description,
        ])->values()->all();

        $working = $owner->workingHours()->get()->map(fn ($w) => [
            'weekday' => $w->weekday,
            'opens_at' => (string) $w->opens_at,
            'closes_at' => (string) $w->closes_at,
        ])->values()->all();

        $busy = Appointment::query()
            ->where('user_id', $owner->id)
            ->confirmed()
            ->where('ends_at', '>=', now()->subDay())
            ->orderBy('starts_at')
            ->limit(40)
            ->get()
            ->map(fn (Appointment $a) => [
                'start' => $a->starts_at->toIso8601String(),
                'end' => $a->ends_at->toIso8601String(),
            ])->all();

        $history = Message::query()
            ->where('dialog_session_id', $session->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn (Message $m) => [
                'direction' => $m->direction,
                'text' => $m->text,
            ])->values()->all();

        return json_encode([
            'reference' => [
                'server_today' => now()->toDateString(),
                'current_year' => (int) now()->year,
                'timezone' => config('app.timezone'),
            ],
            'owner_services_text' => $owner->services_description,
            'services' => $servicesJson,
            'working_hours' => $working,
            'busy' => $busy,
            'history' => $history,
            'latest_message' => $inboundText,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function normalizeAiDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($date)->startOfDay();
        } catch (Throwable) {
            return null;
        }

        $today = now()->startOfDay();
        $y = (int) $today->year;

        if ($parsed->year < $y) {
            $parsed->setYear($y);
        }

        for ($i = 0; $i < 4 && $parsed->lt($today); $i++) {
            $parsed->addYear();
        }

        return $parsed->toDateString();
    }
}
