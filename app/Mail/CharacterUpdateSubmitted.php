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
 * An advancement, adjustment or rewrite waiting on a storyteller, sent to them
 * and copied to the player who filed it.
 *
 * The opposite addressing to DowntimeSubmitted, because the opposite thing is
 * being asked: this one lands in the queue of the person who has to decide it,
 * so reply-to is the player they would be replying to.
 */
class CharacterUpdateSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly CharacterSubmission $submission,
        public readonly string $storyteller,
        public readonly string $player,
    ) {}

    public function envelope(): Envelope
    {
        $character = $this->submission->character?->name ?? 'Character';
        $player = [new Address($this->player)];

        return new Envelope(
            to: [new Address($this->storyteller)],
            cc: $player,
            replyTo: $player,
            subject: "[Sporebound] {$this->label()} submitted — {$character}",
        );
    }

    public function content(): Content
    {
        $submission = $this->submission;
        $character = $submission->character;

        return new Content(
            view: 'emails.character-update-submitted',
            with: [
                'label' => $this->label(),
                'characterName' => $character?->name ?? 'Character',
                'playerName' => $character?->user?->fullName ?? $this->player,
                'venue' => $character?->unit?->name,
                'cycle' => $submission->cycle?->name,
                'note' => $submission->note,
                'entries' => ChangeSummary::entries(
                    $submission->changes_json ?? [],
                    $submission->current_json ?? $character?->resolved(),
                ),
            ],
        );
    }

    private function label(): string
    {
        return $this->submission->typeLabel();
    }
}
