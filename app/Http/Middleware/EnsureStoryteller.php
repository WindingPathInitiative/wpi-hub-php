<?php

namespace App\Http\Middleware;

use App\Exceptions\UserException;
use App\Models\Character;
use App\Models\CharacterSubmission;
use App\Models\Cycle;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the storyteller side of the character system: the acting user must hold
 * an office carrying `character_approve` on the target character's org unit or
 * an ancestor of it (PermissionService::hasOverUnit, which also lets `admin`
 * and National officers through).
 *
 * The org unit is derived from whichever route parameter is present:
 *   {submission} -> its character's unit   {cycle}     -> the cycle's unit
 *   {character}  -> the character's unit   {unit}/{id} -> the unit itself
 *
 * Resolved models are stashed on the request (`storyteller.character` etc.) so
 * controllers don't re-query them.
 */
class EnsureStoryteller
{
    public const ROLE = 'character_approve';

    public function __construct(private readonly PermissionService $perm) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            throw new UserException('User not loaded', 500);
        }

        $this->perm->hasOverUnit($this->orgUnitFor($request), self::ROLE, $request->user());

        return $next($request);
    }

    /**
     * The org unit this request acts on.
     */
    private function orgUnitFor(Request $request): int
    {
        if (($sid = $request->route('submission')) !== null) {
            $submission = CharacterSubmission::with('character')->find((int) $sid);
            if ($submission === null) {
                throw new UserException('Submission not found', 404);
            }
            $request->attributes->set('storyteller.submission', $submission);

            return $this->characterUnit($submission->character);
        }

        if (($cid = $request->route('cycle')) !== null) {
            $cycle = Cycle::find((int) $cid);
            if ($cycle === null) {
                throw new UserException('Cycle not found', 404);
            }
            $request->attributes->set('storyteller.cycle', $cycle);

            return $cycle->org_unit_id;
        }

        if (($chid = $request->route('character')) !== null) {
            $character = Character::find((int) $chid);
            if ($character === null) {
                throw new UserException('Character not found', 404);
            }
            $request->attributes->set('storyteller.character', $character);

            return $this->characterUnit($character);
        }

        $unit = $request->route('unit') ?? $request->route('id');
        if ($unit === null) {
            throw new UserException('No org unit in scope', 500);
        }

        return (int) $unit;
    }

    /**
     * A character with no org unit has no storyteller who can act on it.
     */
    private function characterUnit(?Character $character): int
    {
        if ($character === null) {
            throw new UserException('Character not found', 404);
        }
        if ($character->org_unit_id === null) {
            throw new UserException('Character is not assigned to a game', 403);
        }

        return $character->org_unit_id;
    }
}
