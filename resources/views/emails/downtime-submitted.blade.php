@extends('emails.layout')

@section('title', 'Downtime — ' . $characterName)

@section('heading', 'Downtime filed for ' . $characterName)

@section('subheading')
    {{ $playerName }}@if ($venue) &middot; {{ $venue }}@endif
    @if ($cycle) &middot; {{ $cycle }}@endif
    @if ($dueAt) &middot; due {{ $dueAt->format('j M Y, g:ia') }}@endif
@endsection

@section('body')
    @include('emails.partials.entries', ['emptyMessage' => 'This downtime carried nothing readable.'])
    @include('emails.partials.note')
@endsection

@section('footer')
    Reply to this email to reach your storyteller. Ineligible targets simply fail &mdash;
    they will let you know.
@endsection
