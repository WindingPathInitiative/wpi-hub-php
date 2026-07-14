<?php

namespace App\Services;

use App\Exceptions\UserException;
use App\Models\Office;
use App\Models\OrgUnit;
use App\Models\User;
use App\Support\PresetOfficeContext;
use Illuminate\Support\Collection;

/**
 * Office-based permissions. Ports wpi-hub helpers/permissions.js verbatim.
 *
 * A user's powers come from the offices they hold and the `roles` array on
 * those offices; the `admin` role always passes. Scoping is checked against the
 * materialized-path hierarchy. Permission denials throw UserException with 403;
 * missing target user/unit/office throw 404.
 *
 * Bluebird's Promise semantics map to synchronous PHP: the "pass if either
 * check succeeds" pattern (user assign) is handled by the caller catching both.
 */
class PermissionService
{
    public function __construct(private readonly PresetOfficeContext $preset) {}

    /**
     * Offices held by the officer that carry one of $permissions (admin always
     * qualifies). Throws if the officer holds no offices, or none with a role.
     */
    public function has(array|string $permissions, mixed $officer): Collection
    {
        $permissions = (array) $permissions;
        $permissions[] = 'admin'; // admins always pass role checks

        $offices = $this->normalizeOfficer($officer);

        $matching = $offices->filter(
            fn (Office $o) => is_array($o->roles) && array_intersect($o->roles, $permissions) !== []
        );

        if ($matching->isEmpty()) {
            throw new UserException('No offices with permission', 403);
        }

        return $matching->values();
    }

    /**
     * Officer must hold a qualifying office at the user's org unit or an
     * ancestor. Special case: a user with no org unit requires a National
     * (org id 1) officer.
     */
    public function hasOverUser(mixed $user, array|string $permission, mixed $officer): Collection
    {
        $offices = $this->has($permission, $officer);
        $user = $this->resolveUser($user);
        $officeOrgs = $offices->map(fn (Office $o) => $o->parentOrgID);

        if ($user->orgUnit !== null) {
            $return = collect();

            if ($officeOrgs->contains($user->orgUnit)) {
                $return = $offices->filter(fn (Office $o) => $o->parentOrgID === $user->orgUnit);
            }

            $validOrgs = array_intersect($user->unit->parents(), $officeOrgs->all());
            if ($validOrgs === []) {
                if ($return->isEmpty()) {
                    throw new UserException('Officer not found in chain', 403);
                }
            } else {
                $return = $return->merge(
                    $offices->filter(fn (Office $o) => in_array($o->parentOrgID, $validOrgs, true))
                );
            }

            return $return->unique('id')->values();
        }

        // No org unit => officer must be National.
        if ($officeOrgs->contains(1)) {
            return $offices->filter(fn (Office $o) => $o->parentOrgID === 1)->values();
        }

        throw new UserException('Not national officer', 403);
    }

    /**
     * Officer's office must govern the unit, an ancestor, or National.
     */
    public function hasOverUnit(mixed $unit, array|string $permission, mixed $officer): Collection
    {
        $offices = $this->has($permission, $officer);
        $unit = $this->resolveUnit($unit);
        $officeOrgs = $offices->map(fn (Office $o) => $o->parentOrgID);

        $return = collect();

        if ($officeOrgs->contains(1)) {
            $return = $return->merge($offices->filter(fn (Office $o) => $o->parentOrgID === 1));
        }

        if ($officeOrgs->contains($unit->id)) {
            $return = $return->merge($offices->filter(fn (Office $o) => $o->parentOrgID === $unit->id));
        }

        $valid = array_intersect($unit->parents(), $officeOrgs->all());
        if ($valid === []) {
            if ($return->isEmpty()) {
                throw new UserException('Officer not found in chain', 403);
            }
        } else {
            $return = $return->merge(
                $offices->filter(fn (Office $o) => in_array($o->parentOrgID, $valid, true))
            );
        }

        return $return->unique('id')->values();
    }

    /**
     * Officer must sit above the target office in the office chain (an office in
     * the target's parentPath, or an Assistant whose parent office is).
     */
    public function hasOverOffice(mixed $office, array|string $permission, mixed $officer): Collection
    {
        $office = $this->resolveOffice($office);
        $offices = $this->has($permission, $officer);

        $officeIds = $offices->map(fn (Office $o) => $o->id);
        $parents = array_map('intval', explode('.', (string) $office->parentPath));
        $parentOfficeIDs = $offices
            ->filter(fn (Office $o) => $o->type === 'Assistant')
            ->map(fn (Office $o) => (int) $o->parentOfficeID);

        $return = collect();
        foreach ($parents as $pid) {
            if ($officeIds->contains($pid)) {
                $return = $return->merge($offices->filter(fn (Office $o) => $o->id === $pid));
            } elseif ($parentOfficeIDs->contains($pid)) {
                $return = $return->merge($offices->filter(fn (Office $o) => (int) $o->parentOfficeID === $pid));
            }
        }

        if ($return->isEmpty()) {
            throw new UserException('Officer not found in chain', 403);
        }

        return $return->unique('id')->values();
    }

    /**
     * Offices held by the officer (= Node prefetch/normalizeOfficer). Honours
     * the preset office (?useOffice). Throws if the officer holds none.
     */
    public function prefetch(mixed $officer): Collection
    {
        return $this->normalizeOfficer($officer);
    }

    /**
     * Enforces that you cannot grant/remove roles your own office (or, for an
     * assistant, the primary office) does not hold. Ports checkOfficeRoles.
     *
     * @param  string[]  $newRoles  Roles being set.
     * @param  string[]  $officerRoles  Roles the acting officer holds.
     * @param  string[]|null  $currentRoles  Roles currently on the office (edit).
     * @param  string[]|null  $primaryRoles  Primary office roles (assistant only).
     */
    public function checkOfficeRoles(
        array $newRoles,
        array $officerRoles,
        ?array $currentRoles = null,
        ?array $primaryRoles = null,
    ): void {
        // An assistant cannot exceed its primary office.
        if ($primaryRoles !== null && array_diff($newRoles, $primaryRoles) !== []) {
            throw new UserException('Role not in parent office', 400);
        }

        // Cannot remove a role the office has that you (the editor) lack.
        if ($currentRoles !== null
            && array_diff($currentRoles, $newRoles, $officerRoles) !== []) {
            throw new UserException('Cannot remove role from office that your office does not have', 400);
        }

        // Cannot add a role not held by you (or the primary office).
        if (array_diff($newRoles, $currentRoles ?? [], $officerRoles, $primaryRoles ?? []) !== []) {
            throw new UserException(
                $primaryRoles !== null
                    ? 'Cannot add role to office not held by your office or primary office'
                    : 'Cannot add role to office not held by your office',
                400
            );
        }
    }

    private function normalizeOfficer(mixed $officer): Collection
    {
        if ($officer instanceof Collection) {
            return $officer;
        }

        $id = $officer instanceof User ? $officer->id : (int) $officer;

        $query = Office::query()->where('userID', $id);
        if ($this->preset->has()) {
            $query->where('id', $this->preset->get());
        }

        $offices = $query->get();
        if ($offices->isEmpty()) {
            throw new UserException('User has no offices', 403);
        }

        return $offices;
    }

    private function resolveUser(mixed $user): User
    {
        if ($user instanceof User) {
            if ($user->orgUnit !== null && ! $user->relationLoaded('unit')) {
                $user->load('unit');
            }

            return $user;
        }

        $model = User::with('unit')->find((int) $user);
        if ($model === null) {
            throw new UserException('User not found', 404);
        }

        return $model;
    }

    private function resolveUnit(mixed $unit): OrgUnit
    {
        if ($unit instanceof OrgUnit) {
            return $unit;
        }

        // Node defaulted a missing unit to National (org 1).
        $id = ($unit === null || $unit === '') ? 1 : (int) $unit;

        $model = OrgUnit::find($id);
        if ($model === null) {
            throw new UserException('Org unit not found.', 404);
        }

        return $model;
    }

    private function resolveOffice(mixed $office): Office
    {
        if ($office instanceof Office) {
            return $office;
        }

        $model = Office::find((int) $office);
        if ($model === null) {
            throw new UserException('Office not found', 404);
        }

        return $model;
    }
}
