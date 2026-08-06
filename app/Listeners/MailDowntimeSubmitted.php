<?php

namespace App\Listeners;

use App\Events\DowntimeSubmitted;
use App\Mail\DowntimeSubmitted as DowntimeSubmittedMail;
use App\Services\StorytellerContactService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Mails a filed downtime to the player, copying their storyteller.
 *
 * Nothing here may fail the request: the downtime is already recorded and
 * legal by the time this runs, and a mail outage must not turn that into a
 * 500. Every failure is logged and swallowed.
 *
 * Wired to the event by Laravel's listener discovery — the `handle` type hint
 * is the registration; there is nothing to add in a provider.
 *
 * Not `ShouldQueue` on purpose — QUEUE_CONNECTION is `sync`, so queueing would
 * only move the latency, not remove it. Add it once a worker actually runs.
 */
class MailDowntimeSubmitted
{
    public function __construct(private readonly StorytellerContactService $contacts) {}

    public function handle(DowntimeSubmitted $event): void
    {
        $submission = $event->submission;
        $character = $submission->character;
        $player = $character?->user?->email;

        if ($character === null || ! $player) {
            Log::warning('Downtime filed with no player address to mail', [
                'submission' => $submission->id,
            ]);

            return;
        }

        $storyteller = $this->contacts->emailForCharacter($character);

        if ($storyteller === null) {
            // Still worth mailing the player their own record of it.
            Log::warning('No storyteller address for a filed downtime', [
                'submission' => $submission->id,
                'org_unit' => $character->org_unit_id,
            ]);
        }

        try {
            Mail::send(new DowntimeSubmittedMail($submission, $player, $storyteller));
        } catch (Throwable $e) {
            Log::error('Downtime email failed to send', [
                'submission' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
