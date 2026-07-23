<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\UserException;
use App\Http\Controllers\Concerns\ValidatesLegacy;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Models\OrgUnit;
use Illuminate\Http\Request;

/**
 * Character routes (see "Character Plan.md"). Scoped to the acting user: a user
 * may only list, read, and create their own characters.
 */
class CharacterController extends Controller
{
    use ValidatesLegacy;

    /**
     * Lists the acting user's characters.
     */
    public function index(Request $request)
    {
        $characters = Character::where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get();

        return CharacterResource::collection($characters);
    }

    /**
     * Returns one of the acting user's characters with its improvements.
     */
    public function show(Request $request, int $id)
    {
        $character = Character::with('improvements')->find($id);

        if ($character === null) {
            throw new UserException('Character not found', 404);
        }
        if ($character->user_id !== $request->user()->id) {
            throw new UserException('Not authorized to view this character', 403);
        }

        return new CharacterResource($character);
    }

    /**
     * Saves a new character for the acting user (the wizard export).
     */
    public function store(Request $request)
    {
        $data = $request->all();

        if (empty($data)) {
            throw new UserException('No data provided', 400);
        }

        $attributes = $this->validateLegacy($data, [
            'name' => 'sometimes|string|min:1',
            'org_unit_id' => 'sometimes|nullable|integer',
            'character_json' => 'required|array',
        ]);

        // Name falls back to the export's identity when not supplied explicitly.
        $name = $attributes['name']
            ?? ($attributes['character_json']['identity']['characterName'] ?? null);
        if (! filled($name)) {
            throw new UserException('Character name is required', 400);
        }

        $orgUnitId = $attributes['org_unit_id'] ?? null;
        if ($orgUnitId !== null && ! OrgUnit::whereKey($orgUnitId)->exists()) {
            throw new UserException('Org unit not found', 404);
        }

        $character = new Character([
            'name' => $name,
            'user_id' => $request->user()->id,
            'org_unit_id' => $orgUnitId,
            'character_json' => $attributes['character_json'],
        ]);
        $character->save();

        return response()->json(
            (new CharacterResource($character))->toArray($request),
            201
        );
    }
}
