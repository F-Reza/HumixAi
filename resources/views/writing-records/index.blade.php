@extends('voice.layout')
@section('title', __('Writing records'))
@section('content')
<h1>{{ __('Writing records') }}</h1><p>{{ __('Opt-in draft snapshots and client-reported input events. These records do not prove human authorship.') }}</p><a href="/writing-records/new">{{ __('New writing record') }}</a>
<div class="cards">@forelse($records as $record)<article class="card"><h2>{{ $record->title }}</h2><p>{{ __('Recorded snapshots') }}: {{ $record->frame_count }}</p><a href="/writing-records/{{ $record->id }}">{{ __('Write and replay') }}</a><a href="/writing-records/{{ $record->id }}/export">{{ __('Export writing record') }}</a><form method="post" action="/writing-records/{{ $record->id }}">@csrf @method('DELETE')<button>{{ __('Delete') }}</button></form></article>@empty<p>{{ __('No writing records yet.') }}</p>@endforelse</div><div class="actions">@if($records->previousPageUrl())<a href="{{ $records->previousPageUrl() }}">{{ __('Previous') }}</a>@endif @if($records->nextPageUrl())<a href="{{ $records->nextPageUrl() }}">{{ __('Next') }}</a>@endif</div>
@endsection
