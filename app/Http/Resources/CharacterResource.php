<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a Character. Embeds improvements only when the relation is loaded
 * (show loads it; index does not).
 */
class CharacterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'user_id' => $c->user_id,
            'org_unit_id' => $c->org_unit_id,
            'character_json' => $c->character_json,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
            'improvements' => ImprovementResource::collection($this->whenLoaded('improvements')),
        ];
    }
}
