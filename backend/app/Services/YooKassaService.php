<?php

namespace App\Services;

use App\Models\BillingTransaction;
use App\Models\User;
use RuntimeException;
use YooKassa\Client;

class YooKassaService
{
    /** @return array{payment_id: string, confirmation_url: string|null} */
    public function createPlanPayment(User $user, string $planKey, string $returnUrl): array
    {
        $plan = $this->resolvePlan($planKey);
        if ($plan === null) {
            throw new RuntimeException('Неизвестный тариф');
        }

        $amountKopecks = $plan['amount_kopecks'];
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
            'description' => 'Подписка SmartBookingBot: '.$plan['title'],
            'metadata' => [
                'user_id' => (string) $user->id,
                'plan_key' => $planKey,
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

        $planKey = (string) ($object['metadata']['plan_key'] ?? '');
        if ($planKey !== '') {
            $this->handlePlanPaymentSucceeded($user, $planKey, $amount, $externalId, $data);

            return;
        }

        $this->handleLegacyTopUpSucceeded($user, $amount, $externalId, $data);
    }

    private function handlePlanPaymentSucceeded(User $user, string $planKey, int $amountKopecks, string $externalId, array $data): void
    {
        $plan = $this->resolvePlan($planKey);
        if ($plan === null || $plan['amount_kopecks'] !== $amountKopecks) {
            return;
        }

        BillingTransaction::query()->create([
            'user_id' => $user->id,
            'amount_kopecks' => $amountKopecks,
            'type' => BillingTransaction::TYPE_PLAN_PURCHASE,
            'external_id' => $externalId,
            'meta' => [
                'plan_key' => $planKey,
                'months' => $plan['months'],
                'raw_event' => $data['event'] ?? null,
            ],
        ]);

        $user->extendSubscriptionByMonths($plan['months']);
        $user->refresh();

        if ($user->bot_paused && $user->hasActiveSubscription()) {
            $user->update(['bot_paused' => false]);
        }
    }

    private function handleLegacyTopUpSucceeded(User $user, int $amount, string $externalId, array $data): void
    {
        BillingTransaction::query()->create([
            'user_id' => $user->id,
            'amount_kopecks' => $amount,
            'type' => BillingTransaction::TYPE_TOPUP,
            'external_id' => $externalId,
            'meta' => ['raw_event' => $data['event'] ?? null],
        ]);

        $user->increment('balance_kopecks', $amount);
    }

    /** @return null|array{title: string, months: int, amount_kopecks: int} */
    private function resolvePlan(string $planKey): ?array
    {
        $plans = config('smartbooking.subscription_plans', []);

        return $plans[$planKey] ?? null;
    }
}
