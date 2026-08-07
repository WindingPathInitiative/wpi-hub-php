@extends('emails.layout')

@section('title', $label . ' — ' . $characterName)

@section('heading', $label . ' submitted for ' . $characterName)

@section('subheading')
    {{ $playerName }}@if ($venue) &middot; {{ $venue }}@endif
    @if ($cycle) &middot; {{ $cycle }}@endif
@endsection

@section('body')
    @include('emails.partials.entries', ['emptyMessage' => 'This submission carried nothing readable.'])
    @include('emails.partials.note', ['noteHeading' => 'Note from the player'])
@endsection

@section('footer')
    This is waiting in your queue in the hub, where you can approve or deny it.
    Replying to this email reaches the player.
@endsection
