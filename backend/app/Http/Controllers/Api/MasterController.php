<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Master;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MasterController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()
            ->masters()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'sort_order']);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->isSolo()) {
            return response()->json(['message' => 'В режиме частного мастера можно иметь только одного мастера.'], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $maxOrder = (int) $user->masters()->max('sort_order');

        $master = Master::query()->create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json($master, 201);
    }

    public function update(Request $request, Master $master)
    {
        $this->authorizeOwner($request, $master);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        $master->update($data);

        $user = $request->user();
        if ($user->isSolo() && isset($data['name'])) {
            $user->update(['name' => $data['name']]);
        }

        return response()->json($master);
    }

    public function destroy(Request $request, Master $master)
    {
        $this->authorizeOwner($request, $master);

        $user = $request->user();
        $firstMaster = $user->masters()->orderBy('sort_order')->orderBy('id')->first();
        if ($firstMaster !== null && $firstMaster->id === $master->id) {
            return response()->json(['message' => 'Первого мастера удалить нельзя.'], 422);
        }

        if ($user->isSolo()) {
            return response()->json(['message' => 'В режиме частного мастера нельзя удалять мастера.'], 422);
        }

        $master->delete();

        return response()->json(['ok' => true]);
    }

    public function sync(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'masters' => ['required', 'array', 'min:1'],
            'masters.*.id' => ['nullable', 'integer'],
            'masters.*.name' => ['required', 'string', 'max:255'],
        ]);

        if ($user->isSolo() && count($data['masters']) !== 1) {
            return response()->json(['message' => 'В режиме частного мастера должен быть ровно один мастер.'], 422);
        }

        return DB::transaction(function () use ($user, $data) {
            $existing = $user->masters()->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
            $firstId = $existing->first()?->id;
            $keptIds = [];

            foreach ($data['masters'] as $index => $row) {
                $name = trim($row['name']);
                $id = isset($row['id']) ? (int) $row['id'] : null;

                if ($id !== null && $existing->has($id)) {
                    $master = $existing->get($id);
                    $master->update(['name' => $name, 'sort_order' => $index]);
                    $keptIds[] = $id;
                } else {
                    $master = Master::query()->create([
                        'user_id' => $user->id,
                        'name' => $name,
                        'sort_order' => $index,
                    ]);
                    $keptIds[] = $master->id;
                }
            }

            foreach ($existing as $master) {
                if (in_array($master->id, $keptIds, true)) {
                    continue;
                }
                if ($firstId !== null && $master->id === $firstId) {
                    continue;
                }
                $master->delete();
            }

            if ($user->isSolo()) {
                $primary = $user->masters()->orderBy('sort_order')->orderBy('id')->first();
                if ($primary !== null) {
                    $user->update(['name' => $primary->name]);
                }
            }

            return $user->masters()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'sort_order']);
        });
    }

    private function authorizeOwner(Request $request, Master $master): void
    {
        abort_if($master->user_id !== $request->user()->id, 403);
    }
}
