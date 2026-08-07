<?php

namespace App\Mail;

use App\Models\Character;
use App\Support\SheetSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A new character, sent to the storyteller of the game it joined and copied to
 * the player who made it.
 *
 * The hub has no approval step on a new character, so this is not a request for
 * a decision — it is how the storyteller finds out somebody is about to sit
 * down at their table. Reply-to is the player, because any question about the
 * sheet is a question for them.
 */
class CharacterSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Character $character,
        public readonly string $storyteller,
        public readonly ?string $player = null,
    ) {}

    public function envelope(): Envelope
    {
        $player = $this->player === null ? [] : [new Address($this->player)];

        return new Envelope(
            to: [new Address($this->storyteller)],
            cc: $player,
            replyTo: $player,
            subject: "[Sporebound] New character — {$this->character->name}",
        );
    }

    public function content(): Content
    {
        $character = $this->character;
        $identity = $character->character_json['identity'] ?? [];

        return new Content(
            view: 'emails.character-submitted',
            with: [
                'characterName' => $character->name,
                'playerName' => $character->user?->fullName
                    ?? ($identity['playerName'] ?? null)
                    ?? $this->player,
                'venue' => $character->unit?->name,
                'pronouns' => $identity['pronouns'] ?? null,
                'entries' => SheetSummary::entries($character->resolved()),
            ],
        );
    }
}
