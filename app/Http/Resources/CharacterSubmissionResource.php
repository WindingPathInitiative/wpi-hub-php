<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a CharacterSubmission. The character is embedded only when loaded
 * (the storyteller queue loads it so the ST can see whose sheet this is).
 */
class CharacterSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        $data = [
            'id' => $s->id,
            'character_id' => $s->character_id,
            'cycle_id' => $s->cycle_id,
            'type' => $s->type,
            'status' => $s->status,
            'changes_json' => $s->changes_json,
            'current_json' => $s->current_json,
            'note' => $s->note,
            'submitted_at' => $s->submitted_at,
            'resolved_at' => $s->resolved_at,
            'resolved_by' => $s->resolved_by,
            'created_at' => $s->created_at,
            'updated_at' => $s->updated_at,
        ];

        if ($s->relationLoaded('character') && $s->character !== null) {
            $data['character'] = (new CharacterResource($s->character))->toArray($request);
        }

        if ($s->relationLoaded('cycle') && $s->cycle !== null) {
            $data['cycle'] = (new CycleResource($s->cycle))->toArray($request);
        }

        return $data;
    }
}
