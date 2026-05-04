<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        return ActivityLog::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    public function markRead(Request $request, ActivityLog $activityLog)
    {
        abort_if($activityLog->user_id !== $request->user()->id, 403);
        $activityLog->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
