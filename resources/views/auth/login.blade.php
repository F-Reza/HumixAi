@extends('auth.base')
@section('title', 'Welcome back')
@section('content')
<span class="eyebrow">PICK UP WHERE YOU LEFT OFF</span><h2>Welcome back.</h2><p>Your drafts, your voice, your own little corner to write.</p>
<div class="auth-error" id="authError" role="alert" @if(!$errors->any()) hidden @endif>@if($errors->any()){{ $errors->first() }}@endif</div>
<form action="/login" method="post" id="authForm">@csrf<label for="email">Email address</label><input type="email" name="email" id="email" autocomplete="username" maxlength="254" placeholder="you@example.com" value="{{ old('email') }}" required autofocus><label for="password">Password</label><input type="password" name="password" id="password" autocomplete="current-password" maxlength="128" placeholder="Your password" required><button class="button button-primary auth-submit" type="submit">Sign in to your workspace <span>↗</span></button></form>
<div class="auth-switch">New here? <a href="/register">Create your workspace</a></div>
@endsection
