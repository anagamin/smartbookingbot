<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VkIdOAuthService;
use App\Services\YandexOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OAuthController extends Controller
{
    public function vkStart(Request $request, VkIdOAuthService $vk)
    {
        $userId = $request->user()?->id;
        $data = $vk->startPkce($userId);

        if ($request->expectsJson()) {
            return response()->json($data);
        }

        return redirect()->away($data['url']);
    }

    public function vkCallback(Request $request, VkIdOAuthService $vk)
    {
        $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
            'device_id' => ['required', 'string'],
        ]);

        try {
            $user = $vk->handleCallback(
                $request->query('code'),
                $request->query('state'),
                $request->query('device_id')
            );
        } catch (RuntimeException $e) {
            Log::warning('vk_oauth_failed', ['e' => $e->getMessage()]);

            return redirect()->away($this->frontendError($e->getMessage()));
        }

        $token = $user->createToken('spa')->plainTextToken;

        return redirect()->away($this->frontendSuccess($token));
    }

    public function yandexStart(Request $request, YandexOAuthService $yandex)
    {
        $userId = $request->user()?->id;
        $data = $yandex->start($userId);

        if ($request->expectsJson()) {
            return response()->json($data);
        }

        return redirect()->away($data['url']);
    }

    public function yandexCallback(Request $request, YandexOAuthService $yandex)
    {
        $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $user = $yandex->handleCallback($request->query('code'), $request->query('state'));
        } catch (RuntimeException $e) {
            Log::warning('yandex_oauth_failed', ['e' => $e->getMessage()]);

            return redirect()->away($this->frontendError($e->getMessage()));
        }

        $token = $user->createToken('spa')->plainTextToken;

        return redirect()->away($this->frontendSuccess($token));
    }

    private function frontendSuccess(string $token): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return $base.'/auth/callback?token='.urlencode($token);
    }

    private function frontendError(string $message): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return $base.'/auth/callback?error='.urlencode($message);
    }
}
