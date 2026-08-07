<?php

namespace App\Events;

use App\Models\CharacterSubmission;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A storyteller has approved or denied a submission.
 *
 * One event for both outcomes: the submission's own `status` says which, and
 * the player is written to either way. Storyteller submissions approve
 * themselves the moment they are created, so they never raise this.
 */
class SubmissionReviewed
{
    use Dispatchable;

    public function __construct(public readonly CharacterSubmission $submission) {}
}
