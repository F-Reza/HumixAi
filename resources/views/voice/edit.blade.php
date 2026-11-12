@extends('voice.layout')
@section('content')
<h1>{{ $profile ? __('Edit voice profile') : __('Create voice profile') }}</h1>
<form action="{{ $profile ? '/voice-profiles/'.$profile->id : '/voice-profiles' }}" method="post">@csrf @if($profile)@method('PUT')@endif
<label>{{ __('Name') }}<input name="name" value="{{ old('name', $profile?->name) }}" required maxlength="100"></label>
<label>{{ __('Profile type') }}<select name="kind"><option value="personal" @selected(old('kind', $profile?->kind)==='personal')>{{ __('Personal voice') }}</option><option value="brand" @selected(old('kind', $profile?->kind)==='brand')>{{ __('Brand voice') }}</option></select></label>
<label>{{ __('Language') }}<select name="language"><option value="auto">{{ __('Auto-detect') }}</option>@foreach(\App\Support\Locales::all() as $code=>$label)<option value="{{ $code }}" @selected(old('language', $profile?->language)===$code)>{{ $label }}</option>@endforeach</select></label>
<label>{{ __('Writing sample') }}<textarea dir="auto" name="sample" rows="9" minlength="30" maxlength="3000" required>{{ old('sample', $profile?->sample) }}</textarea></label>
<label>{{ __('Audience and style preferences') }}<textarea dir="auto" name="preferences" rows="3" maxlength="1000">{{ old('preferences', $profile?->preferences) }}</textarea></label>
<label><input type="checkbox" name="storage_consent" value="1" required>{{ __('I agree to store this writing sample and preferences in my account until I delete the profile.') }}</label>
<p>{{ __('Use writing you own or have permission to use. Saving does not send the sample to an AI provider.') }}</p><button type="submit">{{ __('Save changes') }}</button><a href="/voice-profiles">{{ __('Cancel') }}</a></form>
@endsection
