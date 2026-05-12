<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingTransaction;
use App\Services\YooKassaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class BillingController extends Controller
{
    public function plans()
    {
        $plans = config('smartbooking.subscription_plans', []);

        $out = [];
        foreach ($plans as $id => $row) {
            $out[] = [
                'id' => $id,
                'title' => $row['title'],
                'months' => $row['months'],
                'amount_kopecks' => $row['amount_kopecks'],
                'price_rub' => (int) ($row['amount_kopecks'] / 100),
            ];
        }

        return response()->json(['plans' => $out]);
    }

    public function transactions(Request $request)
    {
        return BillingTransaction::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    public function createCheckout(Request $request, YooKassaService $yooKassa)
    {
        $data = $request->validate([
            'plan_key' => ['required', 'string', 'max:32', Rule::in(array_keys(config('smartbooking.subscription_plans', [])))],
            'return_url' => ['required', 'url'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $result = $yooKassa->createPlanPayment(
                $request->user(),
                $data['plan_key'],
                $data['return_url'],
                $data['customer_phone'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
