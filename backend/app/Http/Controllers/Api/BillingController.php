<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingTransaction;
use App\Services\YooKassaService;
use Illuminate\Http\Request;
use RuntimeException;

class BillingController extends Controller
{
    public function transactions(Request $request)
    {
        return BillingTransaction::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    public function createTopUp(Request $request, YooKassaService $yooKassa)
    {
        $data = $request->validate([
            'amount_kopecks' => ['required', 'integer', 'min:100'],
            'return_url' => ['required', 'url'],
        ]);

        try {
            $result = $yooKassa->createTopUpPayment($request->user(), $data['amount_kopecks'], $data['return_url']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
