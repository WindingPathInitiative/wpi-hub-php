<?php

namespace App\Support;

/**
 * Request-scoped holder for the "preset office" (?useOffice / body.useOffice).
 *
 * In Node this lived in a module-global set by the presetOffice middleware and
 * read by permissions.normalizeOfficer. Here it is a scoped singleton (bound
 * per-request in AppServiceProvider) so concurrent requests can't bleed into
 * each other.
 */
class PresetOfficeContext
{
    private ?int $officeId = null;

    public function set(?int $officeId): void
    {
        $this->officeId = $officeId;
    }

    public function get(): ?int
    {
        return $this->officeId;
    }

    public function has(): bool
    {
        return $this->officeId !== null;
    }
}
