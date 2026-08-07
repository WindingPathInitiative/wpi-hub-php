<?php

namespace App\Mail;

use App\Models\CharacterSubmission;
use App\Support\ChangeSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The record of a filed downtime, sent to the player and copied to their
 * storyteller.
 *
 * Reply-to is the storyteller, so a player answering the mail reaches the
 * person who has to adjudicate it — most of a downtime (Undermine, Destroy,
 * Narrative Impact) is resolved in conversation, not in the hub.
 */
class DowntimeSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly CharacterSubmission $submission,
        public readonly string $player,
        public readonly ?string $storyteller = null,
    ) {}

    public function envelope(): Envelope
    {
        $character = $this->submission->character?->name ?? 'Character';
        $cycle = $this->submission->cycle?->name ?? 'no cycle';
        $storyteller = $this->storyteller === null ? [] : [new Address($this->storyteller)];

        return new Envelope(
            to: [new Address($this->player)],
            cc: $storyteller,
            replyTo: $storyteller,
            subject: "[Sporebound] Downtime — {$character} — {$cycle}",
        );
    }

    public function content(): Content
    {
        $submission = $this->submission;
        $character = $submission->character;

        return new Content(
            view: 'emails.downtime-submitted',
            with: [
                'characterName' => $character?->name ?? 'Character',
                'playerName' => $character?->user?->fullName ?? $this->player,
                'venue' => $character?->unit?->name,
                'cycle' => $submission->cycle?->name,
                'dueAt' => $submission->cycle?->downtime_due_at,
                'note' => $submission->note,
                'entries' => ChangeSummary::entries(
                    $submission->changes_json ?? [],
                    $submission->current_json ?? $character?->resolved(),
                ),
            ],
        );
    }
}
