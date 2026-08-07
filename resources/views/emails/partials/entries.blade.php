{{-- A ChangeSummary/SheetSummary rendering: a heading and its lines. --}}
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
    <p>{{ $emptyMessage ?? 'Nothing readable was carried here.' }}</p>
@endforelse
