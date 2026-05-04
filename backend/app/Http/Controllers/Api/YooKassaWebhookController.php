<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\YooKassaService;
use Illuminate\Http\Request;

class YooKassaWebhookController extends Controller
{
    public function __invoke(Request $request, YooKassaService $yooKassa)
    {
        $yooKassa->handleWebhook($request->getContent());

        return response()->json(['ok' => true]);
    }
}
