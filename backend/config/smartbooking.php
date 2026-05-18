<?php

$oauthCallbackPath = static function (string $provider): string {
    $base = rtrim((string) env('APP_URL', 'http://localhost:8000'), '/');

    return (str_ends_with($base, '/api') ? $base : $base.'/api').'/oauth/'.$provider.'/callback';
};

return [
    'public_booking_base_url' => rtrim((string) env('PUBLIC_BOOKING_BASE_URL', 'https://smartbookingbot.ru/book'), '/'),
    /** @deprecated Used only for migrating old balance-based access to subscription_ends_at. */
    'subscription_price_kopecks' => (int) env('SUBSCRIPTION_PRICE_KOPECKS', 100_000),
    'trial_days' => (int) env('TRIAL_DAYS', 30),
    /**
     * Tariffs: payment extends subscription_ends_at from max(now, current end).
     *
     * @var array<string, array{title: string, months: int, amount_kopecks: int}>
     */
    'subscription_plans' => [
        '1m' => ['title' => '1 месяц', 'months' => 1, 'amount_kopecks' => 100_000],
        '6m' => ['title' => '6 месяцев', 'months' => 6, 'amount_kopecks' => 500_000],
        '12m' => ['title' => '12 месяцев', 'months' => 12, 'amount_kopecks' => 800_000],
    ],
    'gptunnel' => [
        'base_url' => rtrim(env('GPTUNNEL_BASE_URL', 'https://gptunnel.ru/v1'), '/'),
        'api_key' => env('GPTUNNEL_API_KEY', ''),
        'model' => env('GPTUNNEL_MODEL', 'gpt-4.1-mini'),
    ],
    'vk_id' => [
        'client_id' => env('VK_ID_CLIENT_ID'),
        'client_secret' => env('VK_ID_CLIENT_SECRET'),
        'redirect_uri' => env('VK_ID_REDIRECT_URI') ?: $oauthCallbackPath('vk'),
        'authorize_url' => env('VK_ID_AUTHORIZE_URL', 'https://id.vk.ru/authorize'),
        'token_url' => env('VK_ID_TOKEN_URL', 'https://id.vk.ru/oauth2/auth'),
        'user_info_url' => env('VK_ID_USER_INFO_URL', 'https://id.vk.ru/oauth2/user_info'),
        // Space-separated scopes for /authorize. Leave empty for default vkid.personal_info only.
        // Enable "Почта" in VK ID app → Доступы before requesting "email". See VK ID create-application docs.
        'oauth_scopes' => env('VK_ID_OAUTH_SCOPES', ''),
    ],
    'yandex' => [
        'client_id' => env('YANDEX_CLIENT_ID'),
        'client_secret' => env('YANDEX_CLIENT_SECRET'),
        'redirect_uri' => env('YANDEX_REDIRECT_URI') ?: $oauthCallbackPath('yandex'),
        'authorize_url' => 'https://oauth.yandex.ru/authorize',
        'token_url' => 'https://oauth.yandex.ru/token',
    ],
    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret_key' => env('YOOKASSA_SECRET_KEY'),
        /** Ставка НДС для чека (1–12). По умолчанию 6 — «НДС не облагается» (часто УСН). См. документацию ЮKassa. */
        'receipt_vat_code' => (int) env('YOOKASSA_RECEIPT_VAT_CODE', 6),
        /** Код системы налогообложения (1–6), если в кассе настроено несколько СНО. Иначе не задавайте. */
        'receipt_tax_system_code' => (($t = env('YOOKASSA_RECEIPT_TAX_SYSTEM_CODE')) !== null && $t !== '')
            ? (int) $t
            : null,
    ],
];
