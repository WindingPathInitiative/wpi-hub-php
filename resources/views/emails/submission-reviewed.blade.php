@extends('emails.layout')

@section('title', $label . ' ' . ($approved ? 'approved' : 'denied') . ' — ' . $characterName)

@section('heading')
    {{ $label }} {{ $approved ? 'approved' : 'denied' }} for {{ $characterName }}
@endsection

@section('subheading')
    {{ $playerName }}@if ($venue) &middot; {{ $venue }}@endif
    @if ($cycle) &middot; {{ $cycle }}@endif
    @if ($storytellerName) &middot; by {{ $storytellerName }}@endif
    @if ($resolvedAt) &middot; {{ $resolvedAt->format('j M Y, g:ia') }}@endif
@endsection

@section('body')
    @include('emails.partials.entries', ['emptyMessage' => 'This submission carried nothing readable.'])
    @include('emails.partials.note', [
        'noteHeading' => $approved ? 'Note from your storyteller' : 'Why it was sent back',
    ])
@endsection

@section('footer')
    @if ($approved)
        Your character has been updated in the hub.
    @else
        Nothing has changed on your character. Edit the submission in the hub and
        send it again once you have.
    @endif
    Reply to this email to reach your storyteller.
@endsection
