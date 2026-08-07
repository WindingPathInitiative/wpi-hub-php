<?php

namespace App\Events;

use App\Models\CharacterSubmission;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A player has sent an advancement, adjustment or rewrite to their storyteller.
 *
 * The sibling of DowntimeSubmitted for everything a player can submit that is
 * not a downtime: same two ways in (a first submission and a resubmit after a
 * rejection), same reason for being an event rather than a mail call.
 */
class CharacterUpdateSubmitted
{
    use Dispatchable;

    public function __construct(public readonly CharacterSubmission $submission) {}
}
