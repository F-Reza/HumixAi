@extends('voice.layout')
@section('title', __('New writing record'))
@section('content')
<h1>{{ __('New writing record') }}</h1><p>{{ __('Existing text is recorded as an initial import, never as newly typed writing. Start with a blank draft or review your imported text.') }}</p>
<form method="post" action="/writing-records">@csrf @if($document)<input type="hidden" name="document_id" value="{{ $document->id }}">@endif<label>{{ __('Name') }}<input name="title" value="{{ old('title', $document?->title) }}" required maxlength="200"></label><label>{{ __('Initial text') }}<textarea name="recording_text" dir="auto" rows="10" maxlength="60000">{{ $document?->current_text }}</textarea></label><label><input type="checkbox" name="storage_consent" value="1" required>{{ __('I consent to encrypted storage of this draft and later recorded snapshots until I delete this writing record.') }}</label><p>{{ __('Recording is optional, requires history enabled, and sends no text to an AI provider. Linked document deletion also deletes its writing records.') }}</p><button>{{ __('Create writing record') }}</button></form>
@endsection
