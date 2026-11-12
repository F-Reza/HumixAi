@extends('auth.base')
@section('title', __('Account security'))
@section('content')
<h2>{{ __('Account security') }}</h2><p>{{ auth()->user()->email }}</p>
@if($errors->any())<p class="auth-error" role="alert">{{ $errors->first() }}</p>@endif
@if(auth()->user()->hasVerifiedEmail())<p>{{ __('Your email is verified.') }}</p>@else<p>{{ __('Your email is not verified.') }}</p><form action="{{ route('verification.send') }}" method="post">@csrf<button class="button button-primary" type="submit" @disabled(!\App\Http\Controllers\AccountSecurityController::emailEnabled())>{{ __('Send verification link') }}</button></form>@endif
@if(!\App\Http\Controllers\AccountSecurityController::emailEnabled())<p>{{ __('Account email is not configured. Contact the administrator.') }}</p>@endif
<a class="button" href="{{ route('password.request') }}">{{ __('Reset password') }}</a><a class="button" href="/voice-profiles">{{ __('Voice profiles') }}</a>
@endsection
