@extends('auth.base')
@section('title', __('Reset password'))
@section('content')
<h2>{{ __('Reset password') }}</h2><p>{{ __('Enter your account email to request a secure reset link.') }}</p>
@if($errors->any())<p class="auth-error" role="alert">{{ $errors->first() }}</p>@endif
@if(!\App\Http\Controllers\AccountSecurityController::emailEnabled())<p>{{ __('Account email is not configured. Contact the administrator.') }}</p>@else
<form action="{{ route('password.email') }}" method="post">@csrf<label>{{ __('Email address') }}<input type="email" name="email" required maxlength="254" autocomplete="email" value="{{ old('email') }}"></label><button class="button button-primary" type="submit">{{ __('Send reset link') }}</button></form>@endif
@endsection
