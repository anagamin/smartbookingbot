<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkingHour;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkingHourController extends Controller
{
    public function index(Request $request)
    {
        return WorkingHour::query()->where('user_id', $request->user()->id)->orderBy('weekday')->orderBy('opens_at')->get();
    }

    public function sync(Request $request)
    {
        $data = $request->validate([
            'slots' => ['required', 'array'],
            'slots.*.weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'slots.*.opens_at' => ['required', 'date_format:H:i'],
            'slots.*.closes_at' => ['required', 'date_format:H:i'],
        ]);

        DB::transaction(function () use ($request, $data) {
            WorkingHour::query()->where('user_id', $request->user()->id)->delete();
            foreach ($data['slots'] as $slot) {
                WorkingHour::query()->create([
                    'user_id' => $request->user()->id,
                    'weekday' => $slot['weekday'],
                    'opens_at' => $slot['opens_at'].':00',
                    'closes_at' => $slot['closes_at'].':00',
                ]);
            }
        });

        return WorkingHour::query()->where('user_id', $request->user()->id)->orderBy('weekday')->get();
    }
}
