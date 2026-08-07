@extends('emails.layout')

@section('title', 'New character — ' . $characterName)

@section('heading', 'New character: ' . $characterName)

@section('subheading')
    {{ $playerName }}@if ($pronouns) ({{ $pronouns }})@endif
    @if ($venue) &middot; {{ $venue }}@endif
@endsection

@section('body')
    @include('emails.partials.entries', [
        'emptyMessage' => 'The saved character has no resolved sheet on it yet.',
    ])
@endsection

@section('footer')
    The sheet lives in the hub, where you can read it in full. Replying to this
    email reaches the player.
@endsection
