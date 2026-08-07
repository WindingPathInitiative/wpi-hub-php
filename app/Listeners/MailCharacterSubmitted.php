<?php

namespace App\Listeners;

use App\Events\CharacterSubmitted;
use App\Mail\CharacterSubmitted as CharacterSubmittedMail;

/**
 * Mails a new character to the storyteller of the game it joined, copying the
 * player who made it.
 *
 * A character can be saved with no game on it at all (the wizard export does
 * not have to name one), and one that names no game has no storyteller to tell.
 * That is not an error — the player attaches it to a game later.
 */
class MailCharacterSubmitted extends CharacterMailListener
{
    public function handle(CharacterSubmitted $event): void
    {
        $character = $event->character;
        $context = ['character' => $character->id, 'mail' => 'character-submitted'];

        $storyteller = $this->storytellerEmail($character);
        if ($storyteller === null) {
            $this->noAddress('storyteller', $context + ['org_unit' => $character->org_unit_id]);

            return;
        }

        $this->send(
            new CharacterSubmittedMail($character, $storyteller, $this->playerEmail($character)),
            $context,
        );
    }
}
