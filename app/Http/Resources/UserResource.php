<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a User. Ports User.serialize() + the publicAttrs/show()/showPrivate
 * gating from wpi-hub.
 *
 *   default      -> public attrs only (no PII)
 *   ->full()     -> adds orgUnit (the show() output, PII still omitted)
 *   ->withPrivate() -> adds PII (email, address, names, fullName); implies full
 *
 * portalID is never emitted.
 */
class UserResource extends JsonResource
{
    public bool $full = false;

    public bool $private = false;

    public function full(bool $value = true): static
    {
        $this->full = $value;

        return $this;
    }

    public function withPrivate(bool $value = true): static
    {
        $this->private = $value;
        if ($value) {
            $this->full = true;
        }

        return $this;
    }

    public function toArray(Request $request): array
    {
        $u = $this->resource;

        // publicAttrs
        $data = [
            'id' => $u->id,
            'membershipNumber' => $u->membershipNumber,
            'nickname' => $u->nickname,
            'membershipType' => $u->membershipType,
            'membershipExpiration' => $u->membershipExpiration,
        ];

        if (! $this->full) {
            return $data;
        }

        $data['orgUnit'] = $u->orgUnit;

        if ($this->private) {
            $data['firstName'] = $u->firstName;
            $data['lastName'] = $u->lastName;
            $data['fullName'] = $u->fullName;
            $data['email'] = $u->email;
            $data['address'] = $u->address;
        }

        return $data;
    }
}
