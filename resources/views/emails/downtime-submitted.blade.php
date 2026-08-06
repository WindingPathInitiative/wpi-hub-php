<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Downtime — {{ $characterName }}</title>
</head>
<body style="font-family: -apple-system, Segoe UI, Helvetica, Arial, sans-serif; line-height: 1.5; color: #1c1c1e;">
    <h1 style="font-size: 18px; margin: 0 0 4px;">Downtime filed for {{ $characterName }}</h1>
    <p style="margin: 0 0 18px; color: #666; font-size: 13px;">
        {{ $playerName }}@if ($venue) &middot; {{ $venue }}@endif
        @if ($cycle) &middot; {{ $cycle }}@endif
        @if ($dueAt) &middot; due {{ $dueAt->format('j M Y, g:ia') }}@endif
    </p>

    @forelse ($entries as $entry)
        <h2 style="font-size: 14px; margin: 18px 0 4px; text-transform: uppercase; letter-spacing: 0.08em; color: #444;">
            {{ $entry['heading'] }}
        </h2>
        @forelse ($entry['lines'] as $line)
            <p style="margin: 0 0 6px;">{{ $line }}</p>
        @empty
            <p style="margin: 0 0 6px; color: #999;">Nothing written down.</p>
        @endforelse
    @empty
        <p>This downtime carried nothing readable.</p>
    @endforelse

    @if ($note)
        <h2 style="font-size: 14px; margin: 18px 0 4px; text-transform: uppercase; letter-spacing: 0.08em; color: #444;">
            Note
        </h2>
        <p style="margin: 0 0 6px;">{{ $note }}</p>
    @endif

    <p style="margin: 24px 0 0; color: #666; font-size: 13px;">
        Reply to this email to reach your storyteller. Ineligible targets simply fail &mdash;
        they will let you know.
    </p>
</body>
</html>
