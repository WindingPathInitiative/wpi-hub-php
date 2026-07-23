<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes an Improvement.
 */
class ImprovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'id' => $i->id,
            'character_id' => $i->character_id,
            'date_added' => $i->date_added,
            'date_approved' => $i->date_approved,
            'improvements_json' => $i->improvements_json,
        ];
    }
}
