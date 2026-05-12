<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    public function index()
    {
        $rows = ContactMessage::query()
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

        ContactMessage::query()->create([
            'message_type' => $data['message_type'],
            'body' => $data['body'],
        ]);

        return response()->json([
            'message' => 'Запрос отправлен.',
        ], 201);
    }
}
