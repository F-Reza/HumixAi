<?php

namespace App\Http\Controllers;

use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function status(Request $request, BillingService $billing): JsonResponse
    {
        abort_unless($request->user(), 401);
        return response()->json(['success' => true, 'data' => $billing->status($request->user())]);
    }
    public function checkout(Request $request, BillingService $billing): JsonResponse
    {
        abort_unless($request->user(), 401);
        $data = $request->validate(['plan' => ['required', Rule::in(array_keys(config('billing.plans')))], 'acknowledge_checkout' => ['required', 'accepted']]);
        return response()->json(['success' => true, 'data' => $billing->checkout($request->user(), $data['plan'])]);
    }
    public function portal(Request $request, BillingService $billing): JsonResponse
    {
        abort_unless($request->user(), 401);
        return response()->json(['success' => true, 'data' => $billing->portal($request->user())]);
    }
    public function webhook(Request $request, BillingService $billing): JsonResponse
    {
        $billing->webhook($request->getContent(), (string) $request->header('Stripe-Signature'));
        return response()->json(['received' => true]);
    }
}
