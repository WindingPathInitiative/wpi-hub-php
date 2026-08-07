<?php

namespace App\Listeners;

use App\Events\DowntimeSubmitted;
use App\Mail\DowntimeSubmitted as DowntimeSubmittedMail;

/**
 * Mails a filed downtime to the player, copying their storyteller.
 *
 * The player is the addressee because this is their record of what they filed;
 * see CharacterMailListener for why nothing here may fail the request.
 */
class MailDowntimeSubmitted extends CharacterMailListener
{
    public function handle(DowntimeSubmitted $event): void
    {
        $submission = $event->submission;
        $character = $submission->character;
        $context = ['submission' => $submission->id, 'mail' => 'downtime-submitted'];

        $player = $this->playerEmail($character);
        if ($player === null) {
            $this->noAddress('player', $context);

            return;
        }

        $storyteller = $this->storytellerEmail($character);
        if ($storyteller === null) {
            // Still worth mailing the player their own record of it.
            $this->noAddress('storyteller', $context + ['org_unit' => $character->org_unit_id]);
        }

        $this->send(new DowntimeSubmittedMail($submission, $player, $storyteller), $context);
    }
}
