<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sex' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'services_description' => ['nullable', 'string', 'max:20000'],
            'bot_paused' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        if (array_key_exists('bot_paused', $data) && $data['bot_paused'] === false && ! $user->hasActiveSubscription()) {
            return response()->json([
                'message' => 'Включить бота можно только при активной подписке. Продлите доступ на странице «Оплата».',
            ], 422);
        }

        $user->update($data);
        $user->refresh();

        return response()->json([
            'user' => $user->cabinetPayload(),
        ]);
    }
}
