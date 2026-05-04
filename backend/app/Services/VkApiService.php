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
}
