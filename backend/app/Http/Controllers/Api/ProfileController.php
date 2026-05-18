<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BookingSlug;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sex' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'services_description' => ['nullable', 'string', 'max:20000'],
            'bot_paused' => ['sometimes', 'boolean'],
            'booking_slug' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('users', 'booking_slug')->ignore($user->id),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $slug = BookingSlug::normalize((string) $value);
                    if (! BookingSlug::isValid($slug)) {
                        $fail('Адрес ссылки: латиница, цифры и дефис, от 3 до 50 символов.');
                    }
                },
            ],
        ]);

        if (array_key_exists('booking_slug', $data)) {
            $data['booking_slug'] = BookingSlug::normalize($data['booking_slug']);
        }

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
