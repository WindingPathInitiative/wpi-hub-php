<?php

namespace App\Events;

use App\Models\Character;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A player has saved a new character out of the wizard.
 *
 * There is no approval step on a new character yet — saving it is the whole of
 * it — so this exists to tell the storyteller of the game a character has
 * landed in, which is the point at which they can look at it.
 */
class CharacterSubmitted
{
    use Dispatchable;

    public function __construct(public readonly Character $character) {}
}
