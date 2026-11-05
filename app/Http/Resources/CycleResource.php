<?php

namespace App\Http\Resources;

use App\Models\OrgUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a Cycle. `is_open` / `downtime_open` / `downtime_in_grace` save every client from
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
            'downtime_grace_ends_at' => $c->downtime_grace_ends_at,
            // The game's clock, so clients show these dates as the game reads them.
            'timezone' => $c->unit?->gameTimezone() ?? OrgUnit::DEFAULT_TIMEZONE,
            'is_open' => $c->opens_at !== null && $c->opens_at->lte(now()),
            'downtime_open' => $c->opens_at !== null && $c->opens_at->lte(now()) && $c->downtimeIsOpen(),
            'downtime_in_grace' => $c->opens_at !== null && $c->opens_at->lte(now()) && $c->downtimeInGrace(),
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];
    }
}
