<?php

namespace App\Services;

use App\DataTransferObjects\AiIntentResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GptunnelClient
{
    private const CHAT_RETRIES = 5;

    private const CHAT_RETRY_SLEEP_MS = 700;

    public function classifyMessage(string $systemPrompt, string $userContent): AiIntentResult
    {
        $cfg = config('smartbooking.gptunnel');
        $apiKey = $cfg['api_key'] ?? '';
        if ($apiKey === '') {
            throw new RuntimeException('GPTUNNEL_API_KEY is not configured');
        }

        $basePayload = [
            'model' => $cfg['model'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            'temperature' => 0.2,
        ];

        $url = $cfg['base_url'].'/chat/completions';

        Log::info('gptunnel_chat_request', [
            'url' => $url,
            'payload' => $basePayload,
        ]);

        $response = $this->postChatWithTransportRetries($url, $apiKey, array_merge($basePayload, [
            'response_format' => ['type' => 'json_object'],
        ]));

        if (! $response->successful()) {
            $response = $this->postChatWithTransportRetries($url, $apiKey, $basePayload);
        }

        if (! $response->successful()) {
            Log::warning('gptunnel_error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('GPT request failed: '.$response->status());
        }

        $responseJson = $response->json();
        Log::info('gptunnel_chat_response', [
            'status' => $response->status(),
            'body' => $responseJson,
        ]);

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new RuntimeException('Empty AI response');
        }

        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        return AiIntentResult::fromJsonArray($decoded);
    }

    /**
     * Повтор при обрыве TLS/TCP (cURL 35 и т.п.); без ретраев по HTTP-коду ответа.
     */
    private function postChatWithTransportRetries(string $url, string $apiKey, array $payload): Response
    {
        return Http::timeout(60)
            ->withToken($apiKey)
            ->acceptJson()
            ->retry(self::CHAT_RETRIES, self::CHAT_RETRY_SLEEP_MS, function (Throwable $e, PendingRequest $request) {
                if ($e instanceof ConnectionException) {
                    Log::warning('gptunnel_connection_retry', ['message' => $e->getMessage()]);

                    return true;
                }

                return false;
            }, throw: false)
            ->post($url, $payload);
    }
}
