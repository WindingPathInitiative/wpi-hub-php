@if ($note)
    <h2 style="font-size: 14px; margin: 18px 0 4px; text-transform: uppercase; letter-spacing: 0.08em; color: #444;">
        {{ $noteHeading ?? 'Note' }}
    </h2>
    <p style="margin: 0 0 6px;">{{ $note }}</p>
@endif
