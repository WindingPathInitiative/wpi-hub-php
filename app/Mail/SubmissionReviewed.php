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
 * A storyteller's decision, sent to the player and copied to the storyteller
 * who made it.
 *
 * Reply-to is the storyteller: a denial is the start of a conversation, and a
 * player answering one should reach the person who wrote the note rather than
 * an unread mailbox.
 *
 * Approved and denied are the same mail with a different verdict on it — the
 * player wants to see the same changes either way — so the subclasses differ
 * only in what the thing being decided is called.
 */
abstract class SubmissionReviewed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly CharacterSubmission $submission,
        public readonly string $player,
        public readonly ?string $storyteller = null,
    ) {}

    /** What this submission is called in the subject and the heading. */
    abstract protected function label(): string;

    public function envelope(): Envelope
    {
        $character = $this->submission->character?->name ?? 'Character';
        $cycle = $this->submission->cycle?->name;
        $storyteller = $this->storyteller === null ? [] : [new Address($this->storyteller)];
        $outcome = $this->approved() ? 'approved' : 'denied';

        return new Envelope(
            to: [new Address($this->player)],
            cc: $storyteller,
            replyTo: $storyteller,
            subject: "[Sporebound] {$this->label()} {$outcome} — {$character}"
                .($cycle === null ? '' : " — {$cycle}"),
        );
    }

    public function content(): Content
    {
        $submission = $this->submission;
        $character = $submission->character;

        return new Content(
            view: 'emails.submission-reviewed',
            with: [
                'label' => $this->label(),
                'approved' => $this->approved(),
                'characterName' => $character?->name ?? 'Character',
                'playerName' => $character?->user?->fullName ?? $this->player,
                'venue' => $character?->unit?->name,
                'cycle' => $submission->cycle?->name,
                'resolvedAt' => $submission->resolved_at,
                'storytellerName' => $submission->resolver?->fullName,
                'note' => $submission->note,
                'entries' => ChangeSummary::entries(
                    $submission->changes_json ?? [],
                    $submission->current_json ?? $character?->resolved(),
                ),
            ],
        );
    }

    protected function approved(): bool
    {
        return $this->submission->status === 'approved';
    }
}
