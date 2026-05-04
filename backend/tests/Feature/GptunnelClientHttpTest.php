<?php

namespace Tests\Feature;

use App\Services\GptunnelClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GptunnelClientHttpTest extends TestCase
{
    public function test_parses_json_object_response(): void
    {
        Config::set('smartbooking.gptunnel', [
            'base_url' => 'https://api.test',
            'api_key' => 'secret',
            'model' => 'test-model',
        ]);

        Http::fake([
            'https://api.test/chat/completions' => Http::sequence()
                ->push(['error' => 'no json_format'], 400)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => '{"intent":"chit_chat","confidence":1,"service":null,"date":null,"time":null,"reply":"Пожалуйста!","needs_owner":false}',
                        ],
                    ]],
                ], 200),
        ]);

        $client = app(GptunnelClient::class);
        $result = $client->classifyMessage('system', 'спасибо');

        $this->assertSame('chit_chat', $result->intent);
        $this->assertSame('Пожалуйста!', $result->reply);
    }
}
