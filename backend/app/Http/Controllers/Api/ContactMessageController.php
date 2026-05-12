<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    public function index(Request $request)
    {
        $rows = $request->user()
            ->contactMessages()
            ->orderByDesc('created_at')
            ->get(['id', 'created_at', 'message_type', 'body', 'response']);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'message_type' => ['required', 'string', 'in:bug,improvement'],
            'body' => ['required', 'string', 'min:5', 'max:10000'],
        ]);

        $request->user()->contactMessages()->create([
            'message_type' => $data['message_type'],
            'body' => $data['body'],
        ]);

        return response()->json([
            'message' => 'Запрос отправлен.',
        ], 201);
    }
}
