<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VkApiService
{
    public function sendGroupMessage(string $accessToken, int $peerId, string $text): bool
    {
        $randomId = random_int(1, PHP_INT_MAX);
        $response = Http::asForm()->timeout(15)->post('https://api.vk.com/method/messages.send', [
            'access_token' => $accessToken,
            'v' => '5.199',
            'peer_id' => $peerId,
            'message' => $text,
            'random_id' => $randomId,
        ]);

        if (! $response->successful()) {
            Log::warning('vk_send_failed', ['body' => $response->body()]);

            return false;
        }

        $data = $response->json();
        if (isset($data['error'])) {
            Log::warning('vk_send_error', $data['error']);

            return false;
        }

        return true;
    }

    public function getUserDisplayName(string $accessToken, int $userId): ?string
    {
        $response = Http::asForm()->timeout(15)->get('https://api.vk.com/method/users.get', [
            'access_token' => $accessToken,
            'user_ids' => $userId,
            'lang' => 0,
            'v' => '5.199',
        ]);

        if (! $response->successful()) {
            Log::warning('vk_users_get_http', ['status' => $response->status()]);

            return null;
        }

        $data = $response->json();
        if (isset($data['error'])) {
            Log::warning('vk_users_get_error', $data['error']);

            return null;
        }

        $user = $data['response'][0] ?? null;
        if (! is_array($user)) {
            return null;
        }

        $first = trim((string) ($user['first_name'] ?? ''));
        $last = trim((string) ($user['last_name'] ?? ''));
        $name = trim($first.' '.$last);

        return $name !== '' ? $name : null;
    }
}
