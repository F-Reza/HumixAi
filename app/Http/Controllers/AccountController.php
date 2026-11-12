<?php

namespace App\Http\Controllers;

use App\Services\DocumentImportService;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function show(Request $request, UsageService $usage, DocumentImportService $imports): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'user' => $request->user(), 'usage' => $usage->summary($request->user()),
            'capabilities' => $imports->capabilities(),
            'billing_available' => app(\App\Services\BillingService::class)->configured(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'sometimes|required|string|max:100', 'history_enabled' => 'sometimes|required|boolean']);
        $user = $request->user();
        if (array_key_exists('name', $data)) { $user->name = trim($data['name']); }
        if (array_key_exists('history_enabled', $data)) { $user->history_enabled = $data['history_enabled']; }
        $user->save();
        return response()->json(['success' => true, 'data' => ['user' => $user]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string|max:128']);
        $user = $request->user();
        if (!Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => ['The password is incorrect.']]);
        }
        \App\Http\Controllers\AdminController::guardLastAdmin($user);
        app(\App\Services\BillingService::class)->deleteAccountSafely($user, fn () => DB::transaction(function () use ($user) {
            \App\Models\User::where('role', 'super_admin')->orderBy('id')->lockForUpdate()->get();
            $fresh = \App\Models\User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            \App\Http\Controllers\AdminController::guardLastAdmin($fresh);
            DB::table('password_reset_tokens')->where('email',$fresh->email)->delete();
            $fresh->delete();
        }));
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['success' => true, 'data' => ['deleted' => true]]);
    }
}
