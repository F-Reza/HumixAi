@extends('auth.base')
@section('title', __('Welcome back'))
@section('content')
<span class="eyebrow">{{ __('PICK UP WHERE YOU LEFT OFF') }}</span><h2>{{ __('Welcome back.') }}</h2><p>{{ __('Your drafts, your voice, your own little corner to write.') }}</p>
<div class="auth-error" id="authError" role="alert" @if(!$errors->any()) hidden @endif>@if($errors->any()){{ $errors->first() }}@endif</div>
<form action="/login" method="post" id="authForm">@csrf<label for="email">{{ __('Email address') }}</label><input dir="auto" type="email" name="email" id="email" autocomplete="username" maxlength="254" placeholder="{{ __('you@example.com') }}" value="{{ old('email') }}" required autofocus><label for="password">{{ __('Password') }}</label><input dir="auto" type="password" name="password" id="password" autocomplete="current-password" maxlength="128" placeholder="{{ __('Your password') }}" required><button class="button button-primary auth-submit" type="submit">{{ __('Sign in to your workspace') }} <span>↗</span></button></form>
<div class="auth-switch">{{ __('New here?') }} <a href="/register">{{ __('Create your workspace') }}</a></div>
<a href="{{ route('password.request') }}">{{ __('Forgot password?') }}</a>
@endsection
