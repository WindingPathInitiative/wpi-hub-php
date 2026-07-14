<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes an OrgUnit. Ports OrgUnit.serialize() + publicAttrs/show() gating.
 *
 *   default  -> public attrs (id, name, code, type, venueType, location)
 *   ->full() -> all columns
 *
 * Always: drops parentPath, drops venueType when null, escapes newlines in
 * defDoc.
 */
class OrgUnitResource extends JsonResource
{
    public bool $full = false;

    public function full(bool $value = true): static
    {
        $this->full = $value;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $u = $this->resource;

        if ($this->full) {
            $data = [
                'id' => $u->id,
                'name' => $u->name,
                'code' => $u->code,
                'venueType' => $u->venueType,
                'location' => $u->location,
                'website' => $u->website,
                'type' => $u->type,
                'defDoc' => $u->defDoc,
            ];
        } else {
            $data = [
                'id' => $u->id,
                'name' => $u->name,
                'code' => $u->code,
                'type' => $u->type,
                'venueType' => $u->venueType,
                'location' => $u->location,
            ];
        }

        if (($data['venueType'] ?? null) === null) {
            unset($data['venueType']);
        }

        if (! empty($data['defDoc'])) {
            $data['defDoc'] = str_replace("\n", '\\n', $data['defDoc']);
        }

        return $data;
    }
}
