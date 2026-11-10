<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, UsageService $usage): JsonResponse
    {
        if (!config('studio.allow_registration', true)) {
            return response()->json(['success' => false, 'message' => 'Registration is not available on this workspace.'], 403);
        }
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:100', 'email' => 'required|email:rfc|max:254|unique:users,email',
            'password' => ['required', 'string', 'confirmed', Password::min(10), 'max:128'],
        ]);
        $key = 'registration:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['success' => false, 'message' => 'Too many registrations. Try again later.'], 429);
        }
        RateLimiter::hit($key, 3600);
        $user = User::create(['name' => trim($data['name']), 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
        Auth::login($user);
        $request->session()->regenerate();
        return response()->json(['success' => true, 'data' => ['user' => $user, 'usage' => $usage->summary($user)]], 201);
    }

    public function login(Request $request, UsageService $usage): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => 'required|email:rfc|max:254', 'password' => 'required|string|max:128']);
        $key = 'login:'.hash('sha256', $request->ip().'|'.$data['email']);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['success' => false, 'message' => 'Too many login attempts. Try again in a minute.'], 429);
        }
        if (!Auth::attempt($data)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => ['The email or password is incorrect.']]);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        return response()->json(['success' => true, 'data' => ['user' => $request->user(), 'usage' => $usage->summary($request->user())]]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['success' => true, 'data' => ['logged_out' => true]]);
    }

    public function me(Request $request, UsageService $usage): JsonResponse
    {
        if (!$request->user()) {
            return response()->json(['success' => false, 'message' => 'Sign in to use your workspace.', 'data' => ['user' => null]], 401);
        }
        return response()->json(['success' => true, 'data' => ['user' => $request->user(), 'usage' => $usage->summary($request->user())]]);
    }
}
