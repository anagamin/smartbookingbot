<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Master;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Support\BookingSlug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'business_mode' => ['sometimes', 'string', Rule::in(['solo', 'salon'])],
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

        DB::transaction(function () use ($user, $data): void {
            if (array_key_exists('business_mode', $data) && $data['business_mode'] === 'solo') {
                $this->consolidateMastersForSolo($user);
            }
            if (array_key_exists('name', $data) && ($user->business_mode ?? 'solo') !== 'salon') {
                $primary = $user->masters()->orderBy('sort_order')->orderBy('id')->first();
                if ($primary !== null) {
                    $primary->update(['name' => $data['name']]);
                }
            }

            $user->update($data);

            if (array_key_exists('business_mode', $data) && $data['business_mode'] === 'salon') {
                if ($user->masters()->count() === 0) {
                    Master::query()->create([
                        'user_id' => $user->id,
                        'name' => $user->name,
                        'sort_order' => 0,
                    ]);
                }
            }
        });

        $user->refresh();

        return response()->json([
            'user' => $user->cabinetPayload(),
        ]);
    }

    private function consolidateMastersForSolo(User $user): void
    {
        $masters = $user->masters()->orderBy('sort_order')->orderBy('id')->get();
        if ($masters->count() <= 1) {
            return;
        }

        $primary = $masters->first();
        foreach ($masters->skip(1) as $extra) {
            Service::query()->where('master_id', $extra->id)->update(['master_id' => $primary->id]);
            WorkingHour::query()->where('master_id', $extra->id)->update(['master_id' => $primary->id]);
            Appointment::query()->where('master_id', $extra->id)->update(['master_id' => $primary->id]);
            $extra->delete();
        }

        $user->update(['name' => $primary->name]);
    }
}
