<?php

namespace App\Listeners;

use App\Models\Character;
use App\Services\StorytellerContactService;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Shared plumbing for the mails the hub sends about a character: who the two
 * correspondents are, and sending without ever failing the request.
 *
 * Nothing a subclass does may throw: by the time one runs, the thing it is
 * reporting is already recorded and legal, and a mail outage must not turn that
 * into a 500. Every failure is logged and swallowed.
 *
 * Subclasses are wired to their events by Laravel's listener discovery — the
 * `handle` type hint is the registration, and this class is abstract so
 * discovery passes over it.
 *
 * Not `ShouldQueue` on purpose — QUEUE_CONNECTION is `sync`, so queueing would
 * only move the latency, not remove it. Add it once a worker actually runs.
 */
abstract class CharacterMailListener
{
    public function __construct(protected readonly StorytellerContactService $contacts) {}

    protected function playerEmail(?Character $character): ?string
    {
        return $character?->user?->email ?: null;
    }

    protected function storytellerEmail(?Character $character): ?string
    {
        return $character === null ? null : $this->contacts->emailForCharacter($character);
    }

    protected function send(Mailable $mail, array $context): void
    {
        try {
            Mail::send($mail);
        } catch (Throwable $e) {
            Log::error('Character email failed to send', $context + ['error' => $e->getMessage()]);
        }
    }

    /**
     * There was nobody to write to, or nobody worth copying. Either way the mail
     * is the least important thing that just happened.
     */
    protected function noAddress(string $role, array $context): void
    {
        Log::warning("No {$role} address for a character email", $context);
    }
}
