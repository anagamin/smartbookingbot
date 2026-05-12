<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Command;

class ProcessSubscriptionsCommand extends Command
{
    protected $signature = 'smartbooking:subscriptions';

    protected $description = 'Ставит бота на паузу, если срок подписки истёк';

    public function handle(): int
    {
        User::query()
            ->where('bot_paused', false)
            ->where(function ($q) {
                $q->whereNull('subscription_ends_at')
                    ->orWhere('subscription_ends_at', '<=', now());
            })
            ->chunkById(50, function ($users) {
                foreach ($users as $user) {
                    $this->pauseExpiredUser($user);
                }
            });

        return self::SUCCESS;
    }

    private function pauseExpiredUser(User $user): void
    {
        if ($user->hasActiveSubscription()) {
            return;
        }

        if (! $user->bot_paused) {
            $user->update(['bot_paused' => true]);
            Notification::query()->create([
                'user_id' => $user->id,
                'title' => 'Бот остановлен',
                'body' => 'Срок подписки закончился. Оформите продление на странице оплаты, чтобы бот снова отвечал клиентам.',
            ]);
        }
    }
}
