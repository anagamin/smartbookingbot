<?php

namespace App\Services;

use App\Models\BillingTransaction;
use App\Models\User;
use RuntimeException;
use YooKassa\Client;
use YooKassa\Common\Exceptions\ApiException;

class YooKassaService
{
    /** @return array{payment_id: string, confirmation_url: string|null} */
    public function createPlanPayment(User $user, string $planKey, string $returnUrl, ?string $customerPhone = null): array
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

        $customer = $this->buildReceiptCustomer($user, $customerPhone);
        if ($customer === []) {
            throw new RuntimeException(
                'Для оплаты нужен email или телефон для фискального чека: укажите email в профиле или передайте номер телефона.'
            );
        }

        $vatCode = (int) config('smartbooking.yookassa.receipt_vat_code', 6);
        if ($vatCode < 1 || $vatCode > 12) {
            throw new RuntimeException('Некорректный YOOKASSA_RECEIPT_VAT_CODE (ожидается 1–12)');
        }

        $amountValue = number_format($amountKopecks / 100, 2, '.', '');
        $itemDescription = $this->truncateReceiptDescription('Подписка SmartBookingBot: '.$plan['title']);

        $receipt = [
            'customer' => $customer,
            'items' => [
                [
                    'description' => $itemDescription,
                    'quantity' => 1.0,
                    'amount' => [
                        'value' => $amountValue,
                        'currency' => 'RUB',
                    ],
                    'vat_code' => $vatCode,
                    'payment_mode' => 'full_payment',
                    'payment_subject' => 'service',
                    'measure' => 'piece',
                ],
            ],
            'internet' => true,
        ];

        $taxSystemCode = config('smartbooking.yookassa.receipt_tax_system_code');
        if (is_int($taxSystemCode) && $taxSystemCode >= 1 && $taxSystemCode <= 6) {
            $receipt['tax_system_code'] = $taxSystemCode;
        }

        $client = new Client;
        $client->setAuth($shopId, $secret);

        $idempotenceKey = uniqid('pay_', true);

        try {
            $payment = $client->createPayment([
                'amount' => [
                    'value' => $amountValue,
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
                'receipt' => $receipt,
            ], $idempotenceKey);
        } catch (ApiException $e) {
            $message = trim($e->getMessage());
            if ($e->getError() !== null) {
                $message = trim(
                    ($e->getError()->getDescription() ?? '').' '
                    .($e->getError()->getCode() ? 'Код: '.$e->getError()->getCode().'. ' : '')
                    .($e->getError()->getParameter() ? 'Параметр: '.$e->getError()->getParameter().'.' : '')
                );
            }
            throw new RuntimeException($message !== '' ? $message : 'Ошибка ЮKassa', 0, $e);
        }

        return [
            'payment_id' => $payment->getId(),
            'confirmation_url' => $payment->getConfirmation()?->getConfirmationUrl(),
        ];
    }

    /** @return array<string, string> */
    private function buildReceiptCustomer(User $user, ?string $customerPhone): array
    {
        $email = $user->email !== null && $user->email !== ''
            ? filter_var($user->email, FILTER_VALIDATE_EMAIL)
            : false;
        if (is_string($email) && $email !== '') {
            return ['email' => $email];
        }

        $normalized = $this->normalizeReceiptPhone($customerPhone);
        if ($normalized !== null) {
            return ['phone' => $normalized];
        }

        return [];
    }

    private function normalizeReceiptPhone(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null || $digits === '') {
            return null;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '7'.$digits;
        }

        if (strlen($digits) >= 10 && strlen($digits) <= 15 && str_starts_with($digits, '7')) {
            return $digits;
        }

        return null;
    }

    private function truncateReceiptDescription(string $text): string
    {
        $max = 128;

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) <= $max) {
                return $text;
            }

            return mb_substr($text, 0, $max);
        }

        return strlen($text) <= $max ? $text : substr($text, 0, $max);
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
