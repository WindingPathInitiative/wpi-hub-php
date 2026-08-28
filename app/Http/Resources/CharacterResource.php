<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a Character.
 *
 *   default    -> summary row (list/roster): no envelope, no sheet
 *   ->full()   -> the v2 envelope and the cached resolved sheet
 *   ->budget() -> attaches the improvement budget summary
 *
 * Submissions are embedded only when the relation is loaded (show does; index
 * does not). `player_name` likewise appears only when `user` is loaded — the
 * storyteller screens need whose character this is.
 */
class CharacterResource extends JsonResource
{
    public bool $full = false;

    public ?array $budget = null;

    public function full(bool $value = true): static
    {
        $this->full = $value;

        return $this;
    }

    public function budget(?array $summary): static
    {
        $this->budget = $summary;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $c = $this->resource;

        $data = [
            'id' => $c->id,
            'name' => $c->name,
            'user_id' => $c->user_id,
            'org_unit_id' => $c->org_unit_id,
            'retired_at' => $c->retired_at,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];

        if ($this->full) {
            $data['character_json'] = $c->character_json;
            $data['current_json'] = $c->current_json;
        }

        if ($this->budget !== null) {
            $data['budget'] = $this->budget;
        }

        if ($this->resource->relationLoaded('user')) {
            $data['player_name'] = $c->user?->nickname;
        }

        if ($this->resource->relationLoaded('unit')) {
            $data['unit'] = $c->unit ? (new OrgUnitResource($c->unit))->toArray($request) : null;
        }

        if ($this->resource->relationLoaded('submissions')) {
            $data['submissions'] = CharacterSubmissionResource::collection($c->submissions)->toArray($request);
        }

        return $data;
    }
}
