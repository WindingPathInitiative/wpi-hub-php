<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes an Office. Ports Office.serialize() + publicAttrs/show() gating.
 * roles is always an array (the model cast replaces the manual JSON parse).
 *
 *   default  -> public attrs
 *   ->full() -> all columns
 *
 * Composite shapes (with nested user/parents/children, and the field-dropping
 * in GET /office/:id) are assembled in the controllers.
 */
class OfficeResource extends JsonResource
{
    public bool $full = false;

    public function full(bool $value = true): static
    {
        $this->full = $value;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $o = $this->resource;

        if ($this->full) {
            return [
                'id' => $o->id,
                'name' => $o->name,
                'email' => $o->email,
                'type' => $o->type,
                'parentOfficeID' => $o->parentOfficeID,
                'parentPath' => $o->parentPath,
                'parentOrgID' => $o->parentOrgID,
                'userID' => $o->userID,
                'roles' => $o->roles,
            ];
        }

        return [
            'id' => $o->id,
            'name' => $o->name,
            'type' => $o->type,
            'email' => $o->email,
            'roles' => $o->roles,
            'parentOrgID' => $o->parentOrgID,
            'parentOfficeID' => $o->parentOfficeID,
            'userID' => $o->userID,
        ];
    }
}
