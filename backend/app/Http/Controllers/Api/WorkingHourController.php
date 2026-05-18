<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Master;
use App\Models\WorkingHour;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkingHourController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'master_id' => ['required', 'integer'],
        ]);

        $masterId = (int) $request->query('master_id');
        $this->authorizeMaster($request, $masterId);

        return WorkingHour::query()
            ->where('master_id', $masterId)
            ->orderBy('weekday')
            ->orderBy('opens_at')
            ->get();
    }

    public function sync(Request $request)
    {
        $data = $request->validate([
            'master_id' => ['required', 'integer'],
            'slots' => ['required', 'array'],
            'slots.*.weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'slots.*.opens_at' => ['required', 'date_format:H:i'],
            'slots.*.closes_at' => ['required', 'date_format:H:i'],
        ]);

        $masterId = (int) $data['master_id'];
        $this->authorizeMaster($request, $masterId);
        $userId = $request->user()->id;

        DB::transaction(function () use ($userId, $masterId, $data) {
            WorkingHour::query()->where('master_id', $masterId)->delete();
            foreach ($data['slots'] as $slot) {
                WorkingHour::query()->create([
                    'user_id' => $userId,
                    'master_id' => $masterId,
                    'weekday' => $slot['weekday'],
                    'opens_at' => $slot['opens_at'].':00',
                    'closes_at' => $slot['closes_at'].':00',
                ]);
            }
        });

        return WorkingHour::query()
            ->where('master_id', $masterId)
            ->orderBy('weekday')
            ->get();
    }

    private function authorizeMaster(Request $request, int $masterId): void
    {
        $exists = Master::query()
            ->where('id', $masterId)
            ->where('user_id', $request->user()->id)
            ->exists();
        abort_if(! $exists, 422, 'Invalid master');
    }
}
