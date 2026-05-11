<?php

$oauthCallbackPath = static function (string $provider): string {
    $base = rtrim((string) env('APP_URL', 'http://localhost:8000'), '/');

    return (str_ends_with($base, '/api') ? $base : $base.'/api').'/oauth/'.$provider.'/callback';
};

return [
    'subscription_price_kopecks' => (int) env('SUBSCRIPTION_PRICE_KOPECKS', 100_000),
    'trial_days' => (int) env('TRIAL_DAYS', 30),
    'gptunnel' => [
        'base_url' => rtrim(env('GPTUNNEL_BASE_URL', 'https://gptunnel.ru/v1'), '/'),
        'api_key' => env('GPTUNNEL_API_KEY', ''),
        'model' => env('GPTUNNEL_MODEL', 'gpt-4.1-mini'),
    ],
    'vk_id' => [
        'client_id' => env('VK_ID_CLIENT_ID'),
        'client_secret' => env('VK_ID_CLIENT_SECRET'),
        'redirect_uri' => env('VK_ID_REDIRECT_URI') ?: $oauthCallbackPath('vk'),
        'authorize_url' => env('VK_ID_AUTHORIZE_URL', 'https://id.vk.com/authorize'),
        'token_url' => env('VK_ID_TOKEN_URL', 'https://id.vk.com/oauth2/token'),
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
    ],
];
