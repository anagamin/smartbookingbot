<?php

namespace App\Jobs;

use App\Models\Dialog;
use App\Models\DialogSession;
use App\Models\Message;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\VkProcessedEvent;
use App\Services\ConversationHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $socialAccountId,
        public readonly int $vkGroupId,
        public readonly int $peerId,
        public readonly int $fromId,
        public readonly string $text,
        public readonly string $idempotencyKey,
    ) {}

    public function handle(ConversationHandler $handler): void
    {
        DB::transaction(function () use ($handler) {
            $marker = VkProcessedEvent::query()->firstOrCreate(
                [
                    'vk_group_id' => $this->vkGroupId,
                    'event_id' => $this->idempotencyKey,
                ],
            );

            if (! $marker->wasRecentlyCreated) {
                return;
            }

            $account = SocialAccount::query()->find($this->socialAccountId);
            if (! $account || $account->provider !== SocialAccount::PROVIDER_VK_GROUP) {
                return;
            }

            $owner = User::query()->find($account->user_id);
            if (! $owner) {
                return;
            }

            $dialog = Dialog::query()->firstOrCreate(
                [
                    'social_account_id' => $account->id,
                    'external_client_id' => (string) $this->fromId,
                ],
                [
                    'user_id' => $owner->id,
                    'client_name' => 'VK '.$this->fromId,
                    'last_message_at' => now(),
                ]
            );

            $session = $this->resolveSession($dialog);
            $handler->handleInbound($owner, $account, $dialog, $session, $this->text, $this->peerId);
        });
    }

    private function resolveSession(Dialog $dialog): DialogSession
    {
        $lastInbound = $dialog->messages()
            ->where('direction', Message::DIRECTION_INBOUND)
            ->orderByDesc('id')
            ->first();

        $gapHours = $lastInbound !== null ? $lastInbound->created_at->diffInHours(now()) : 999;

        $open = DialogSession::query()
            ->where('dialog_id', $dialog->id)
            ->where('status', DialogSession::STATUS_OPEN)
            ->orderByDesc('id')
            ->first();

        if ($open && $gapHours < 24) {
            return $open;
        }

        if ($open && $gapHours >= 24) {
            $open->update(['status' => DialogSession::STATUS_CLOSED, 'closed_at' => now()]);
        }

        return DialogSession::query()->create([
            'dialog_id' => $dialog->id,
            'state' => 'open',
            'status' => DialogSession::STATUS_OPEN,
            'started_at' => now(),
        ]);
    }
}
