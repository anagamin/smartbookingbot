<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Master;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'master_id' => ['nullable', 'integer'],
        ]);

        $q = Service::query()->where('user_id', $request->user()->id);
        if ($request->filled('master_id')) {
            $this->authorizeMaster($request, (int) $request->query('master_id'));
            $q->where('master_id', (int) $request->query('master_id'));
        }

        return $q->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'master_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price_kopecks' => ['required', 'integer', 'min:0'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'is_active' => ['boolean'],
        ]);

        $this->authorizeMaster($request, (int) $data['master_id']);

        $service = Service::query()->create([
            ...$data,
            'user_id' => $request->user()->id,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($service, 201);
    }

    public function update(Request $request, Service $service)
    {
        $this->authorizeOwner($request, $service);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price_kopecks' => ['sometimes', 'integer', 'min:0'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $service->update($data);

        return response()->json($service);
    }

    public function destroy(Request $request, Service $service)
    {
        $this->authorizeOwner($request, $service);
        $service->delete();

        return response()->json(['ok' => true]);
    }

    private function authorizeOwner(Request $request, Service $service): void
    {
        abort_if($service->user_id !== $request->user()->id, 403);
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
