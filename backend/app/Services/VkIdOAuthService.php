<?php

namespace App\Services;

use App\Models\OauthPkceState;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class VkIdOAuthService
{
    public function startPkce(?int $userId = null): array
    {
        $state = Str::random(48);
        $codeVerifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        OauthPkceState::query()->create([
            'state' => $state,
            'code_verifier' => $codeVerifier,
            'provider' => 'vk_id',
            'user_id' => $userId,
            'expires_at' => now()->addMinutes(10),
        ]);

        $cfg = config('smartbooking.vk_id');
        $params = [
            'response_type' => 'code',
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $cfg['redirect_uri'],
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        $scopes = trim((string) ($cfg['oauth_scopes'] ?? ''));
        if ($scopes !== '') {
            $params['scope'] = $scopes;
        }

        // RFC3986: spaces in scope must be %20 (VK examples), not "+" from RFC1738.
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $url = rtrim($cfg['authorize_url'], '?').'?'.$query;

        return ['url' => $url, 'state' => $state];
    }

    public function handleCallback(string $code, string $state, string $deviceId): User
    {
        $row = OauthPkceState::query()->where('state', $state)->where('expires_at', '>', now())->first();
        if (! $row) {
            throw new RuntimeException('Invalid or expired OAuth state');
        }

        $cfg = config('smartbooking.vk_id');
        $linkUserId = $row->user_id;
        $codeVerifier = $row->code_verifier;
        $storedScopes = trim((string) ($cfg['oauth_scopes'] ?? ''));
        $storedScopes = $storedScopes !== '' ? $storedScopes : 'vkid.personal_info';

        $response = Http::asForm()->timeout(30)->post($cfg['token_url'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $cfg['redirect_uri'],
            'client_id' => $cfg['client_id'],
            'code_verifier' => $codeVerifier,
            'device_id' => $deviceId,
            'state' => $state,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('VK token exchange failed: '.$response->body());
        }

        $row->delete();

        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Missing access_token');
        }

        $userInfoUrl = $cfg['user_info_url'] ?? 'https://id.vk.ru/oauth2/user_info';
        $userInfo = Http::asForm()
            ->timeout(15)
            ->post($userInfoUrl, [
                'client_id' => $cfg['client_id'],
                'access_token' => $accessToken,
            ])
            ->json();

        $vkNumericId = $userInfo['user']['user_id'] ?? $userInfo['user']['id'] ?? null;
        if ($vkNumericId === null) {
            throw new RuntimeException('VK user id not found in user_info');
        }
        $vkId = (string) $vkNumericId;
        $first = $userInfo['user']['first_name'] ?? 'VK';
        $last = $userInfo['user']['last_name'] ?? '';
        $name = trim($first.' '.$last);
        $email = $userInfo['user']['email'] ?? null;

        if ($linkUserId) {
            $user = User::query()->findOrFail($linkUserId);
            SocialAccount::query()->updateOrCreate(
                ['user_id' => $user->id, 'provider' => SocialAccount::PROVIDER_VK_ID],
                [
                    'provider_user_id' => $vkId,
                    'access_token' => $accessToken,
                    'refresh_token' => $response->json('refresh_token'),
                    'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 3600)),
                    'scopes' => $storedScopes,
                ]
            );

            return $user;
        }

        $account = SocialAccount::query()
            ->where('provider', SocialAccount::PROVIDER_VK_ID)
            ->where('provider_user_id', $vkId)
            ->first();

        if ($account) {
            $account->update([
                'access_token' => $accessToken,
                'refresh_token' => $response->json('refresh_token'),
                'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 3600)),
            ]);

            return $account->user;
        }

        $safeEmail = $email ?: 'vk_'.$vkId.'@vk.local';
        if (User::query()->where('email', $safeEmail)->exists()) {
            $safeEmail = 'vk_'.$vkId.'_'.Str::lower(Str::random(6)).'@vk.local';
        }

        $user = User::query()->create([
            'name' => $name !== '' ? $name : 'VK '.$vkId,
            'email' => $safeEmail,
            'password' => Str::password(32),
            'trial_ends_at' => now()->addDays((int) config('smartbooking.trial_days', 30)),
            'next_billing_at' => now()->addDays((int) config('smartbooking.trial_days', 30)),
        ]);

        SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_VK_ID,
            'provider_user_id' => $vkId,
            'access_token' => $accessToken,
            'refresh_token' => $response->json('refresh_token'),
            'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 3600)),
            'scopes' => $storedScopes,
        ]);

        return $user;
    }
}
