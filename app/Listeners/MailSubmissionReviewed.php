<?php

namespace App\Listeners;

use App\Events\SubmissionReviewed;
use App\Mail\CharacterUpdateReviewed;
use App\Mail\DowntimeReviewed;

/**
 * Mails a storyteller's decision to the player, copying the storyteller.
 *
 * A downtime and a character update get the same treatment and differ only in
 * what the mail calls them, so the type picks the mailable and nothing else.
 */
class MailSubmissionReviewed extends CharacterMailListener
{
    public function handle(SubmissionReviewed $event): void
    {
        $submission = $event->submission;
        $character = $submission->character;
        $context = ['submission' => $submission->id, 'mail' => 'submission-reviewed'];

        $player = $this->playerEmail($character);
        if ($player === null) {
            $this->noAddress('player', $context);

            return;
        }

        $storyteller = $this->storytellerEmail($character);
        if ($storyteller === null) {
            // The verdict still has to reach the player, note and all.
            $this->noAddress('storyteller', $context + ['org_unit' => $character->org_unit_id]);
        }

        $mail = $submission->type === 'downtime'
            ? new DowntimeReviewed($submission, $player, $storyteller)
            : new CharacterUpdateReviewed($submission, $player, $storyteller);

        $this->send($mail, $context);
    }
}
