@extends('auth.base')
@section('title', __('Choose a new password'))
@section('content')
<h2>{{ __('Choose a new password') }}</h2>
@if($errors->any())<p class="auth-error" role="alert">{{ $errors->first() }}</p>@endif
<form action="{{ route('password.update') }}" method="post">@csrf<input type="hidden" name="token" value="{{ $token }}"><label>{{ __('Email address') }}<input type="email" name="email" value="{{ old('email', request('email')) }}" required maxlength="254" autocomplete="email"></label><label>{{ __('Password') }}<input type="password" name="password" minlength="10" maxlength="128" required autocomplete="new-password"></label><label>{{ __('Confirm password') }}<input type="password" name="password_confirmation" minlength="10" maxlength="128" required autocomplete="new-password"></label><p>{{ __('Resetting your password revokes API tokens, public shares and other signed-in sessions.') }}</p><button class="button button-primary" type="submit">{{ __('Reset password') }}</button></form>
@endsection
