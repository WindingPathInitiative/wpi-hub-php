<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a Cycle. `is_open` / `downtime_open` save every client from
 * re-deriving the same clock comparisons.
 */
class CycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            'id' => $c->id,
            'org_unit_id' => $c->org_unit_id,
            'name' => $c->name,
            'improvements_granted' => $c->improvements_granted,
            'opens_at' => $c->opens_at,
            'downtime_due_at' => $c->downtime_due_at,
            'is_open' => $c->opens_at !== null && $c->opens_at->lte(now()),
            'downtime_open' => $c->opens_at !== null && $c->opens_at->lte(now()) && $c->downtimeIsOpen(),
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];
    }
}
