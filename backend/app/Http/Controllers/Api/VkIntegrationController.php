<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\Http\Request;

class VkIntegrationController extends Controller
{
    public function showGroup(Request $request)
    {
        $acc = SocialAccount::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', SocialAccount::PROVIDER_VK_GROUP)
            ->first();

        return response()->json([
            'connected' => $acc !== null,
            'group_id' => $acc?->provider_user_id,
            'meta' => $acc ? [
                'has_callback_secret' => filled($acc->meta['callback_secret'] ?? null),
                'has_confirmation' => filled($acc->meta['confirmation_code'] ?? null),
            ] : null,
        ]);
    }

    public function storeGroup(Request $request)
    {
        $data = $request->validate([
            'group_id' => ['required', 'string', 'max:32'],
            'access_token' => ['required', 'string'],
            'callback_secret' => ['required', 'string', 'max:255'],
            'confirmation_code' => ['required', 'string', 'max:512'],
        ]);

        SocialAccount::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'provider' => SocialAccount::PROVIDER_VK_GROUP,
            ],
            [
                'provider_user_id' => $data['group_id'],
                'access_token' => $data['access_token'],
                'meta' => [
                    'callback_secret' => $data['callback_secret'],
                    'confirmation_code' => $data['confirmation_code'],
                ],
            ]
        );

        return response()->json(['ok' => true]);
    }
}
