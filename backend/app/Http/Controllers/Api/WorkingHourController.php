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

        return $this->hoursForMaster($request->user()->id, $masterId);
    }

    public function sync(Request $request)
    {
        $data = $request->validate([
            'master_id' => ['required', 'integer'],
            'slots' => ['required', 'array'],
            'slots.*.weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'slots.*.opens_at' => ['required', 'date_format:H:i,H:i:s'],
            'slots.*.closes_at' => ['required', 'date_format:H:i,H:i:s'],
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
                    'opens_at' => $this->toStorageTime($slot['opens_at']),
                    'closes_at' => $this->toStorageTime($slot['closes_at']),
                ]);
            }
        });

        return $this->hoursForMaster($userId, $masterId);
    }

    private function hoursForMaster(int $userId, int $masterId)
    {
        $hours = WorkingHour::query()
            ->where('master_id', $masterId)
            ->orderBy('weekday')
            ->orderBy('opens_at')
            ->get();
        if ($hours->isNotEmpty()) {
            return $hours;
        }

        return WorkingHour::query()
            ->where('user_id', $userId)
            ->whereNull('master_id')
            ->orderBy('weekday')
            ->orderBy('opens_at')
            ->get();
    }

    private function toStorageTime(string $value): string
    {
        return strlen($value) === 5 ? $value.':00' : $value;
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
