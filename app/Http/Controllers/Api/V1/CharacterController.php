<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\UserException;
use App\Http\Controllers\Concerns\ResolvesCharacters;
use App\Http\Controllers\Concerns\ValidatesLegacy;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Models\CharacterSubmission;
use App\Models\OrgUnit;
use App\Services\CharacterBudgetService;
use App\Services\SubmissionService;
use App\Support\CharacterEnvelope;
use Illuminate\Http\Request;

/**
 * Characters (see sporebound-character "advancedments-plan.md").
 *
 * Player routes are scoped to the acting user: you may only list, read, create,
 * and edit your own characters. The roster and the anchor routes are the
 * storyteller side, gated by the `storyteller` middleware.
 */
class CharacterController extends Controller
{
    use ResolvesCharacters;
    use ValidatesLegacy;

    public function __construct(
        private readonly CharacterBudgetService $budget,
        private readonly SubmissionService $submissions,
    ) {}

    /**
     * Lists the acting user's characters (retired ones only on request).
     */
    public function index(Request $request)
    {
        $characters = Character::where('user_id', $request->user()->id)
            ->when(! $request->boolean('includeRetired'), fn ($q) => $q->active())
            ->orderBy('name')
            ->get();

        return CharacterResource::collection($characters);
    }

    /**
     * Returns a character with its submissions and improvement budget.
     *
     * Readable by the owner or by a storyteller over the character's game: the
     * storyteller screens need the envelope to render a sheet, diff a pending
     * submission against it, and pick the Anchor an anchor edit targets.
     */
    public function show(Request $request, int $id)
    {
        $character = $this->ownedOrStoryteldCharacter($request, $id);
        $character->load(['submissions', 'unit']);

        return (new CharacterResource($character))
            ->full()
            ->budget($this->budget->summary($character));
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
            'current_json' => 'sometimes|nullable|array',
        ]);

        // A v1 export is lifted into the v2 envelope on the way in, so stored
        // characters are always one shape.
        $envelope = CharacterEnvelope::normalize($attributes['character_json']);

        // Name falls back to the export's identity when not supplied explicitly.
        $name = $attributes['name'] ?? ($envelope['identity']['characterName'] ?? null);
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
            'character_json' => $envelope,
            'current_json' => $attributes['current_json'] ?? $envelope['current'] ?? null,
        ]);
        $character->save();

        return response()->json(
            (new CharacterResource($character))->full()->toArray($request),
            201
        );
    }

    /**
     * Renames a character or moves it to another game. Moving games re-bases the
     * improvement budget on the new unit's cycles.
     */
    public function update(Request $request, int $id)
    {
        $character = $this->ownedCharacter($request, $id);

        $attributes = $this->validateLegacy($request->all(), [
            'name' => 'sometimes|string|min:1',
            'org_unit_id' => 'sometimes|nullable|integer',
            'current_json' => 'sometimes|nullable|array',
        ]);

        if (array_key_exists('org_unit_id', $attributes)) {
            $orgUnitId = $attributes['org_unit_id'];
            if ($orgUnitId !== null && ! OrgUnit::whereKey($orgUnitId)->exists()) {
                throw new UserException('Org unit not found', 404);
            }
            if ($orgUnitId !== $character->org_unit_id && $this->hasPendingSubmissions($character)) {
                throw new UserException('Resolve pending submissions before moving this character', 400);
            }
            $character->org_unit_id = $orgUnitId;
        }

        if (isset($attributes['name'])) {
            $character->name = $attributes['name'];
        }
        if (array_key_exists('current_json', $attributes)) {
            $character->current_json = $attributes['current_json'];
        }

        $character->save();

        return (new CharacterResource($character))->full();
    }

    /**
     * Retires a character: it drops off the roster and accepts no further
     * submissions. Either the player or a storyteller may do this.
     */
    public function retire(Request $request, int $id)
    {
        $character = $this->ownedOrStoryteldCharacter($request, $id);

        if ($character->isRetired()) {
            throw new UserException('Character is already retired', 400);
        }

        $character->retired_at = now();
        $character->save();

        return new CharacterResource($character);
    }

    /**
     * Brings a retired character back.
     */
    public function unretire(Request $request, int $id)
    {
        $character = $this->ownedOrStoryteldCharacter($request, $id);

        if (! $character->isRetired()) {
            throw new UserException('Character is not retired', 400);
        }

        $character->retired_at = null;
        $character->save();

        return new CharacterResource($character);
    }

    /**
     * Storyteller roster: every character assigned to the org unit.
     */
    public function roster(Request $request, int $unit)
    {
        $characters = Character::where('org_unit_id', $unit)
            ->when(! $request->boolean('includeRetired'), fn ($q) => $q->active())
            ->with('user')
            ->orderBy('name')
            ->get();

        return CharacterResource::collection($characters);
    }

    /**
     * Storyteller direct edit: grants an Anchor. Recorded as an auto-approved
     * storyteller submission so it shows up in the character's history.
     */
    public function addAnchor(Request $request, int $character)
    {
        $target = $this->storyteldCharacter($request, $character);

        $attributes = $this->validateLegacy($request->all(), [
            'anchor' => 'required|array',
            'note' => 'sometimes|nullable|string',
            'current_json' => 'sometimes|nullable|array',
        ]);

        return $this->recordStorytellerChange($request, $target, [
            'kind' => 'storyteller.add-anchor',
            'payload' => ['anchor' => $attributes['anchor']],
        ], $attributes);
    }

    /**
     * Storyteller direct edit: destroys an Anchor. Germs attached to it survive
     * but come loose — the client's replay handles that; the player re-homes
     * them with an adjustment submission.
     */
    public function removeAnchor(Request $request, int $character, string $anchorId)
    {
        $target = $this->storyteldCharacter($request, $character);

        $attributes = $this->validateLegacy($request->all(), [
            'reason' => 'required|string|min:1',
            'note' => 'sometimes|nullable|string',
            'current_json' => 'sometimes|nullable|array',
        ]);

        $sheet = $target->resolved();
        if ($sheet !== null) {
            $anchorIds = array_column($sheet['anchors'] ?? [], 'anchorId');
            if (! in_array($anchorId, $anchorIds, true)) {
                throw new UserException('Anchor not found on this character', 404);
            }
        }

        return $this->recordStorytellerChange($request, $target, [
            'kind' => 'storyteller.remove-anchor',
            'payload' => ['anchorId' => $anchorId, 'reason' => $attributes['reason']],
        ], $attributes);
    }

    /**
     * Creates and immediately approves a storyteller submission.
     */
    private function recordStorytellerChange(Request $request, Character $character, array $change, array $attributes)
    {
        $submission = new CharacterSubmission([
            'character_id' => $character->id,
            'cycle_id' => null,
            'type' => 'storyteller',
            'status' => 'submitted',
            'changes_json' => [$change],
            'current_json' => $attributes['current_json'] ?? null,
            'note' => $attributes['note'] ?? null,
            'submitted_at' => now(),
        ]);
        $this->submissions->validate($character, $submission);
        $submission->save();

        $submission = $this->submissions->approve(
            $submission->fresh('character'),
            $request->user(),
            $attributes['current_json'] ?? null,
        );

        return response()->json(
            (new CharacterResource($character->fresh()))->full()->toArray($request)
            + ['submission_id' => $submission->id],
            201
        );
    }

    private function hasPendingSubmissions(Character $character): bool
    {
        return CharacterSubmission::where('character_id', $character->id)->pending()->exists();
    }
}
