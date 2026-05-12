<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\VkApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class VkIntegrationController extends Controller
{
    public function showGroup(Request $request, VkApiService $vkApi)
    {
        $webhookUrl = URL::to('/api/webhooks/vk');

        $acc = SocialAccount::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', SocialAccount::PROVIDER_VK_GROUP)
            ->first();

        if ($acc === null) {
            return response()->json([
                'status' => 'none',
                'group_id' => null,
                'webhook_url' => $webhookUrl,
            ]);
        }

        if ($this->isAwaitingConfirmation($acc)) {
            $this->syncConfirmationFromVk($acc, $vkApi, $webhookUrl);
            $acc->refresh();
        }

        $status = $this->resolveStatus($acc);

        return response()->json([
            'status' => $status,
            'group_id' => $acc->provider_user_id,
            'webhook_url' => $webhookUrl,
        ]);
    }

    public function storeGroup(Request $request)
    {
        if ($request->boolean('detach')) {
            return $this->destroyGroup($request);
        }

        $data = $request->validate([
            'group_id' => ['required', 'string', 'max:32'],
            'access_token' => ['required', 'string'],
            'callback_secret' => ['required', 'string', 'max:255'],
            'confirmation_code' => ['required', 'string', 'max:512'],
        ]);

        $existing = SocialAccount::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', SocialAccount::PROVIDER_VK_GROUP)
            ->first();

        $meta = $existing?->meta ?? [];
        unset($meta['callback_confirmed_at']);
        $meta['callback_secret'] = $data['callback_secret'];
        $meta['confirmation_code'] = $data['confirmation_code'];
        $meta['confirmation_pending'] = true;

        SocialAccount::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'provider' => SocialAccount::PROVIDER_VK_GROUP,
            ],
            [
                'provider_user_id' => $data['group_id'],
                'access_token' => $data['access_token'],
                'meta' => $meta,
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function destroyGroup(Request $request)
    {
        SocialAccount::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', SocialAccount::PROVIDER_VK_GROUP)
            ->delete();

        return response()->json(['ok' => true]);
    }

    private function isAwaitingConfirmation(SocialAccount $acc): bool
    {
        $meta = $acc->meta ?? [];

        return ($meta['confirmation_pending'] ?? false) === true
            && empty($meta['callback_confirmed_at']);
    }

    private function syncConfirmationFromVk(SocialAccount $acc, VkApiService $vkApi, string $webhookUrl): void
    {
        $groupId = (int) $acc->provider_user_id;
        if ($groupId <= 0) {
            return;
        }

        $token = $acc->access_token;
        if (! is_string($token) || $token === '') {
            return;
        }

        $state = $vkApi->callbackServerState($token, $groupId, $webhookUrl);
        if ($state !== 'ok') {
            return;
        }

        $meta = $acc->meta ?? [];
        $meta['callback_confirmed_at'] = now()->toIso8601String();
        $meta['confirmation_pending'] = false;
        $acc->meta = $meta;
        $acc->save();
    }

    private function resolveStatus(SocialAccount $acc): string
    {
        $meta = $acc->meta ?? [];

        if (($meta['confirmation_pending'] ?? false) === true && empty($meta['callback_confirmed_at'])) {
            return 'pending_confirmation';
        }

        return 'attached';
    }
}
