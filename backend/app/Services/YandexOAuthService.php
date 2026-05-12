<?php

namespace App\Services;

use App\Models\OauthPkceState;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class YandexOAuthService
{
    public function start(?int $userId = null): array
    {
        $state = Str::random(48);
        OauthPkceState::query()->create([
            'state' => $state,
            'code_verifier' => '',
            'provider' => 'yandex',
            'user_id' => $userId,
            'expires_at' => now()->addMinutes(10),
        ]);

        $cfg = config('smartbooking.yandex');
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $cfg['redirect_uri'],
            'state' => $state,
        ]);

        return ['url' => $cfg['authorize_url'].'?'.$query, 'state' => $state];
    }

    public function handleCallback(string $code, string $state): User
    {
        $row = OauthPkceState::query()->where('state', $state)->where('expires_at', '>', now())->first();
        if (! $row || $row->provider !== 'yandex') {
            throw new RuntimeException('Invalid or expired OAuth state');
        }

        $linkUserId = $row->user_id;
        $stateKey = $state;

        try {
            $cfg = config('smartbooking.yandex');
            $response = Http::asForm()->timeout(30)->post($cfg['token_url'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Yandex token exchange failed: '.$response->body());
            }

            $accessToken = $response->json('access_token');
            if (! is_string($accessToken) || $accessToken === '') {
                throw new RuntimeException('Missing access_token');
            }

            $info = Http::timeout(15)->withToken($accessToken)->get('https://login.yandex.ru/info?format=json')->json();
            $yandexId = (string) ($info['id'] ?? '');
            if ($yandexId === '') {
                throw new RuntimeException('Yandex id missing');
            }
            $email = $info['default_email'] ?? ($info['login'].'@yandex.ru');
            $name = $info['display_name'] ?? $info['real_name'] ?? 'Yandex '.$yandexId;

            if ($linkUserId) {
                $user = User::query()->findOrFail($linkUserId);
                SocialAccount::query()->updateOrCreate(
                    ['user_id' => $user->id, 'provider' => SocialAccount::PROVIDER_YANDEX],
                    [
                        'provider_user_id' => $yandexId,
                        'access_token' => $accessToken,
                        'refresh_token' => $response->json('refresh_token'),
                        'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 3600)),
                        'scopes' => 'login:info',
                    ]
                );

                return $user;
            }

            $existing = SocialAccount::query()
                ->where('provider', SocialAccount::PROVIDER_YANDEX)
                ->where('provider_user_id', $yandexId)
                ->first();
            if ($existing) {
                $existing->update([
                    'access_token' => $accessToken,
                    'refresh_token' => $response->json('refresh_token'),
                    'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 3600)),
                ]);

                return $existing->user;
            }

            $trialEnd = now()->addDays((int) config('smartbooking.trial_days', 30));
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Str::password(32),
                'trial_ends_at' => $trialEnd,
                'next_billing_at' => $trialEnd,
                'subscription_ends_at' => $trialEnd,
            ]);

            SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => SocialAccount::PROVIDER_YANDEX,
                'provider_user_id' => $yandexId,
                'access_token' => $accessToken,
                'refresh_token' => $response->json('refresh_token'),
                'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 3600)),
                'scopes' => 'login:info',
            ]);

            return $user;
        } finally {
            OauthPkceState::query()->where('state', $stateKey)->delete();
        }
    }
}
