<?php

namespace App\Services;

use App\DataTransferObjects\AiIntentResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GptunnelClient
{
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

        Log::info('gptunnel_chat_request', [
            'url' => $cfg['base_url'].'/chat/completions',
            'payload' => $basePayload,
        ]);

        $response = Http::timeout(60)
            ->withToken($apiKey)
            ->acceptJson()
            ->post($cfg['base_url'].'/chat/completions', array_merge($basePayload, [
                'response_format' => ['type' => 'json_object'],
            ]));

        if (! $response->successful()) {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->acceptJson()
                ->post($cfg['base_url'].'/chat/completions', $basePayload);
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
}
