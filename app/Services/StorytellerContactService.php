<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Office;
use App\Models\OrgUnit;

/**
 * Who to write to about a character.
 *
 * The primary storyteller of a character's venue is the Primary office over
 * that org unit holding `character_approve` — which is exactly what
 * OfficeFactoryService creates for a Venue from the `Game Storyteller`
 * template in config/wpi.php.
 *
 * A vacant venue office is not a dead end: permissions scope up the org chain
 * (see PermissionService::hasOverUnit), so a Chapter Owner also holds
 * `character_approve` over that venue and is the next best person to tell.
 */
class StorytellerContactService
{
    /**
     * The address to copy on a character's submissions, or null when the chain
     * turns up nobody. Callers degrade rather than failing.
     */
    public function emailForCharacter(Character $character): ?string
    {
        if ($character->org_unit_id === null) {
            return null;
        }

        $unit = OrgUnit::find($character->org_unit_id);
        if ($unit === null) {
            return null;
        }

        // The venue itself, then its ancestors nearest-first (parents() is in
        // path order, so National comes last).
        $orgIds = array_merge([$unit->id], array_reverse($unit->parents()));

        foreach ($orgIds as $orgId) {
            $email = $this->emailForUnit((int) $orgId);
            if ($email !== null) {
                return $email;
            }
        }

        return null;
    }

    /**
     * The office's own address if it has one, else whoever holds it. An office
     * that is both vacant and address-less has nobody to tell.
     */
    private function emailForUnit(int $orgId): ?string
    {
        $offices = Office::query()
            ->where('parentOrgID', $orgId)
            ->where('type', 'Primary')
            ->with('user')
            ->get();

        foreach ($offices as $office) {
            if (! is_array($office->roles) || ! in_array('character_approve', $office->roles, true)) {
                continue;
            }

            $email = $office->email ?: $office->user?->email;
            if ($email) {
                return $email;
            }
        }

        return null;
    }
}
