<?php

namespace App\Events;

use App\Models\CharacterSubmission;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A downtime has been committed for a cycle.
 *
 * An event rather than a mail call in the controller because the same thing
 * happens on both paths into `submitted`: a first submission, and a resubmit
 * after a storyteller sent it back. It also keeps mail out of the code that
 * decides whether the submission is legal.
 */
class DowntimeSubmitted
{
    use Dispatchable;

    public function __construct(public readonly CharacterSubmission $submission) {}
}
