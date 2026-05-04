<?php

namespace App\Console\Commands;

use App\Models\BillingTransaction;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Command;

class ProcessSubscriptionsCommand extends Command
{
    protected $signature = 'smartbooking:subscriptions';

    protected $description = 'Ежемесячное списание абонплаты и пауза бота при недостатке средств';

    public function handle(): int
    {
        $price = (int) config('smartbooking.subscription_price_kopecks', 100_000);

        User::query()
            ->where('bot_paused', false)
            ->chunkById(50, function ($users) use ($price) {
                foreach ($users as $user) {
                    $this->processUser($user, $price);
                }
            });

        return self::SUCCESS;
    }

    private function processUser(User $user, int $price): void
    {
        if ($user->isInTrialPeriod()) {
            return;
        }

        if ($user->next_billing_at === null || now()->lt($user->next_billing_at)) {
            return;
        }

        if ($user->balance_kopecks >= $price) {
            $user->balance_kopecks -= $price;
            $user->next_billing_at = now()->addMonth();
            $user->save();
            BillingTransaction::query()->create([
                'user_id' => $user->id,
                'amount_kopecks' => -$price,
                'type' => BillingTransaction::TYPE_SUBSCRIPTION,
                'meta' => ['period' => now()->toDateString()],
            ]);

            return;
        }

        if (! $user->bot_paused) {
            $user->update(['bot_paused' => true]);
            Notification::query()->create([
                'user_id' => $user->id,
                'title' => 'Бот остановлен',
                'body' => 'Недостаточно средств для абонплаты. Пополните баланс, чтобы бот снова отвечал клиентам.',
            ]);
        }
    }
}
