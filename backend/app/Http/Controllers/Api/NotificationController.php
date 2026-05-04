<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return Notification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    public function unreadSnapshot(Request $request)
    {
        $userId = $request->user()->id;
        $count = Notification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
        $latest = Notification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'count' => $count,
            'latest' => $latest ? [
                'id' => $latest->id,
                'title' => $latest->title,
                'body' => $latest->body,
            ] : null,
        ]);
    }

    public function markRead(Request $request, Notification $notification)
    {
        abort_if($notification->user_id !== $request->user()->id, 403);
        $notification->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
