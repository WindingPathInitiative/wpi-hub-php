<?php

namespace App\Listeners;

use App\Events\CharacterUpdateSubmitted;
use App\Mail\CharacterUpdateSubmitted as CharacterUpdateSubmittedMail;

/**
 * Mails an advancement, adjustment or rewrite to the storyteller who has to
 * decide it, copying the player who filed it.
 *
 * Unlike a downtime, the storyteller is the addressee — so a game with nobody
 * holding `character_approve` has nobody to send to, and the mail is dropped
 * rather than sent to the player alone. The submission is queued either way.
 */
class MailCharacterUpdateSubmitted extends CharacterMailListener
{
    public function handle(CharacterUpdateSubmitted $event): void
    {
        $submission = $event->submission;
        $character = $submission->character;
        $context = ['submission' => $submission->id, 'mail' => 'character-update-submitted'];

        $player = $this->playerEmail($character);
        if ($player === null) {
            $this->noAddress('player', $context);

            return;
        }

        $storyteller = $this->storytellerEmail($character);
        if ($storyteller === null) {
            $this->noAddress('storyteller', $context + ['org_unit' => $character->org_unit_id]);

            return;
        }

        $this->send(new CharacterUpdateSubmittedMail($submission, $storyteller, $player), $context);
    }
}
