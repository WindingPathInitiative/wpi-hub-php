<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\UserException;
use App\Http\Middleware\EnsureStoryteller;
use App\Models\Character;
use App\Services\PermissionService;
use Illuminate\Http\Request;

/**
 * Character lookup with the two authorization shapes the character routes use:
 * "mine" (players) and "a game I storytell" (the storyteller middleware).
 */
trait ResolvesCharacters
{
    /**
     * A character the acting user owns.
     *
     * @param  string[]  $with
     */
    protected function ownedCharacter(Request $request, int $id, array $with = []): Character
    {
        $character = Character::with($with)->find($id);

        if ($character === null) {
            throw new UserException('Character not found', 404);
        }
        if ($character->user_id !== $request->user()->id) {
            throw new UserException('Not authorized to view this character', 403);
        }

        return $character;
    }

    /**
     * A character on a route already gated by the `storyteller` middleware,
     * which resolved and authorized it on the way in.
     */
    protected function storyteldCharacter(Request $request, int $id): Character
    {
        $character = $request->attributes->get('storyteller.character');

        if ($character instanceof Character && $character->id === $id) {
            return $character;
        }

        $character = Character::find($id);
        if ($character === null) {
            throw new UserException('Character not found', 404);
        }

        return $character;
    }

    /**
     * A character the acting user owns *or* storytells for. Used by the routes
     * both sides share, which therefore can't be gated by middleware alone.
     */
    protected function ownedOrStoryteldCharacter(Request $request, int $id): Character
    {
        $character = Character::find($id);

        if ($character === null) {
            throw new UserException('Character not found', 404);
        }
        if ($character->user_id === $request->user()->id) {
            return $character;
        }
        if ($character->org_unit_id === null) {
            throw new UserException('Not authorized to change this character', 403);
        }

        // Resolved here rather than injected: this is a trait shared by
        // controllers with differing constructors.
        app(PermissionService::class)->hasOverUnit(
            $character->org_unit_id,
            EnsureStoryteller::ROLE,
            $request->user(),
        );

        return $character;
    }
}
