<?php

namespace App\Services;

use App\Models\BillingTransaction;
use App\Models\User;
use RuntimeException;
use YooKassa\Client;

class YooKassaService
{
    public function createTopUpPayment(User $user, int $amountKopecks, string $returnUrl): array
    {
        if ($amountKopecks < 100) {
            throw new RuntimeException('Minimum amount 1 RUB');
        }

        $shopId = config('smartbooking.yookassa.shop_id');
        $secret = config('smartbooking.yookassa.secret_key');
        if (! $shopId || ! $secret) {
            throw new RuntimeException('YooKassa is not configured');
        }

        $client = new Client;
        $client->setAuth($shopId, $secret);

        $idempotenceKey = uniqid('pay_', true);
        $payment = $client->createPayment([
            'amount' => [
                'value' => number_format($amountKopecks / 100, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $returnUrl,
            ],
            'capture' => true,
            'description' => 'Пополнение баланса SmartBookingBot',
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
        ], $idempotenceKey);

        return [
            'payment_id' => $payment->getId(),
            'confirmation_url' => $payment->getConfirmation()?->getConfirmationUrl(),
        ];
    }

    public function handleWebhook(string $rawBody): void
    {
        $data = json_decode($rawBody, true);
        if (! is_array($data) || ($data['event'] ?? '') !== 'payment.succeeded') {
            return;
        }

        $object = $data['object'] ?? [];
        $userId = (int) ($object['metadata']['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        $externalId = (string) ($object['id'] ?? '');
        if ($externalId === '' || BillingTransaction::query()->where('external_id', $externalId)->exists()) {
            return;
        }

        $value = (string) ($object['amount']['value'] ?? '0');
        $amount = (int) round(((float) $value) * 100);

        BillingTransaction::query()->create([
            'user_id' => $user->id,
            'amount_kopecks' => $amount,
            'type' => BillingTransaction::TYPE_TOPUP,
            'external_id' => $externalId,
            'meta' => ['raw_event' => $data['event']],
        ]);

        $user->increment('balance_kopecks', $amount);
        $user->refresh();
        $price = (int) config('smartbooking.subscription_price_kopecks', 100_000);
        if ($user->bot_paused && ! $user->isInTrialPeriod() && $user->balance_kopecks >= $price) {
            $user->update(['bot_paused' => false]);
        }
    }
}
