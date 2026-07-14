<?php

namespace App\Services;

use App\Models\Office;
use App\Models\OrgUnit;
use RuntimeException;

/**
 * Creates the default (manager) office for a newly created org unit. Ports the
 * static Office.makeOfficeForUnit() from wpi-hub models/office.js.
 *
 * The new office is attached under the parent org's manager office, so the
 * office hierarchy mirrors the org hierarchy.
 */
class OfficeFactoryService
{
    public function makeOfficeForUnit(OrgUnit $unit, string $type = 'manager'): Office
    {
        $templates = config('wpi.templates');

        if (! $unit->type) {
            throw new RuntimeException('Unit type not set');
        }
        if (! isset($templates['rootOffices'][$type])) {
            throw new RuntimeException("Unknown type \"{$type}\"");
        }

        $data = $templates[$unit->type][$type] ?? null;
        if ($data === null) {
            throw new RuntimeException('Office config not found');
        }

        // The immediate parent org id (org-unit parents() drops self).
        $parents = $unit->parents();
        $parentOrgId = (int) end($parents);

        // Find the parent org's manager office to nest under.
        $pathLike = (string) $templates['rootOffices'][$type]; // "1"
        if ($parentOrgId !== 1) {
            $pathLike .= '.%';
        }

        $parent = Office::query()
            ->where('parentOrgID', $parentOrgId)
            ->where('parentPath', 'LIKE', $pathLike)
            ->first();

        if ($parent === null) {
            throw new RuntimeException('Parent not found');
        }

        $office = new Office;
        $office->name = $data['name'];
        $office->type = 'Primary';
        $office->parentOrgID = $unit->id;
        $office->parentPath = $parent->parentPath;
        $office->roles = $data['roles'];

        return $office->insertWithPath();
    }
}
