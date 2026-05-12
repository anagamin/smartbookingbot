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

    /**
     * Check Callback API server row for this app URL (VK API groups.getCallbackServers).
     * Status 1 = server OK (VK Callback API 5.x).
     *
     * @return 'ok'|'pending'|'not_found'
     */
    public function callbackServerState(string $accessToken, int $groupId, string $expectedWebhookUrl): string
    {
        $response = Http::timeout(15)->get('https://api.vk.com/method/groups.getCallbackServers', [
            'access_token' => $accessToken,
            'v' => '5.199',
            'group_id' => $groupId,
        ]);

        if (! $response->successful()) {
            Log::warning('vk_get_callback_servers_http', ['status' => $response->status()]);

            return 'not_found';
        }

        $data = $response->json();
        if (isset($data['error'])) {
            Log::info('vk_get_callback_servers_error', $data['error']);

            return 'not_found';
        }

        $items = $data['response']['items'] ?? [];
        if (! is_array($items)) {
            return 'not_found';
        }

        $expected = $this->normalizeWebhookUrl($expectedWebhookUrl);

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = $this->normalizeWebhookUrl((string) ($item['url'] ?? ''));
            if ($url === '' || $url !== $expected) {
                continue;
            }
            $status = (int) ($item['status'] ?? -1);

            return $status === 1 ? 'ok' : 'pending';
        }

        return 'not_found';
    }

    private function normalizeWebhookUrl(string $url): string
    {
        return strtolower(rtrim(trim($url), '/'));
    }
}
