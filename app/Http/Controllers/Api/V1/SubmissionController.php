<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\UserException;
use App\Http\Controllers\Concerns\ResolvesCharacters;
use App\Http\Controllers\Concerns\ValidatesLegacy;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Http\Resources\CharacterSubmissionResource;
use App\Models\Character;
use App\Models\CharacterSubmission;
use App\Models\Cycle;
use App\Services\CharacterBudgetService;
use App\Services\SubmissionService;
use Illuminate\Http\Request;

/**
 * Character submissions: a player drafts a batch of changes, submits it, and a
 * storyteller approves or rejects it whole.
 *
 * Player routes are owner-scoped; the queue and approve/reject are gated by the
 * `storyteller` middleware.
 */
class SubmissionController extends Controller
{
    use ResolvesCharacters;
    use ValidatesLegacy;

    public function __construct(
        private readonly SubmissionService $service,
        private readonly CharacterBudgetService $budget,
    ) {}

    /**
     * Creates a draft submission against the acting user's character.
     */
    public function store(Request $request, int $id)
    {
        $character = $this->ownedCharacter($request, $id);

        if ($character->isRetired()) {
            throw new UserException('Character is retired', 400);
        }

        $attributes = $this->validateLegacy($request->all(), [
            'type' => 'required|string|in:'.implode(',', CharacterSubmission::PLAYER_TYPES),
            'cycle_id' => 'sometimes|nullable|integer',
            'changes_json' => 'required|array|min:1',
            'current_json' => 'sometimes|nullable|array',
            'note' => 'sometimes|nullable|string',
        ]);

        $submission = new CharacterSubmission([
            'character_id' => $character->id,
            'cycle_id' => $this->resolveCycle($character, $attributes),
            'type' => $attributes['type'],
            'status' => 'draft',
            'changes_json' => array_values($attributes['changes_json']),
            'current_json' => $attributes['current_json'] ?? null,
            'note' => $attributes['note'] ?? null,
        ]);
        $submission->save();

        return response()->json(
            (new CharacterSubmissionResource($submission))->toArray($request),
            201
        );
    }

    /**
     * Edits a submission while it is a draft or has come back rejected. Editing
     * a rejected submission returns it to draft; the player resubmits it.
     */
    public function update(Request $request, int $id, int $sid)
    {
        $character = $this->ownedCharacter($request, $id);
        $submission = $this->submissionOf($character, $sid);

        if (! $submission->isEditable()) {
            throw new UserException('Only a draft or rejected submission can be edited', 400);
        }

        $attributes = $this->validateLegacy($request->all(), [
            'cycle_id' => 'sometimes|nullable|integer',
            'changes_json' => 'sometimes|array|min:1',
            'current_json' => 'sometimes|nullable|array',
            'note' => 'sometimes|nullable|string',
        ]);

        if (array_key_exists('cycle_id', $attributes)) {
            $submission->cycle_id = $this->resolveCycle($character, $attributes);
        }
        if (isset($attributes['changes_json'])) {
            $submission->changes_json = array_values($attributes['changes_json']);
        }
        if (array_key_exists('current_json', $attributes)) {
            $submission->current_json = $attributes['current_json'];
        }
        if (array_key_exists('note', $attributes)) {
            $submission->note = $attributes['note'];
        }

        $submission->status = 'draft';
        $submission->resolved_at = null;
        $submission->resolved_by = null;
        $submission->save();

        return new CharacterSubmissionResource($submission);
    }

    /**
     * Discards a draft.
     */
    public function destroy(Request $request, int $id, int $sid)
    {
        $character = $this->ownedCharacter($request, $id);
        $submission = $this->submissionOf($character, $sid);

        if (! $submission->isEditable()) {
            throw new UserException('Only a draft or rejected submission can be deleted', 400);
        }

        $submission->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Submits a draft for storyteller approval.
     */
    public function submit(Request $request, int $id, int $sid)
    {
        $character = $this->ownedCharacter($request, $id);
        $submission = $this->submissionOf($character, $sid);

        $this->service->submit($character, $submission);

        return new CharacterSubmissionResource($submission);
    }

    /**
     * Storyteller queue: submissions across a game, pending by default.
     */
    public function queue(Request $request, int $unit)
    {
        $status = $request->query('status', 'submitted');

        if (! in_array($status, array_merge(CharacterSubmission::STATUSES, ['all']), true)) {
            throw new UserException('Invalid data provided: unknown status', 400);
        }

        $submissions = CharacterSubmission::query()
            ->whereHas('character', fn ($q) => $q->where('org_unit_id', $unit))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            // Drafts are the player's private workspace.
            ->when($status === 'all', fn ($q) => $q->where('status', '!=', 'draft'))
            ->with(['character.user', 'cycle'])
            ->orderBy('id')
            ->get();

        return CharacterSubmissionResource::collection($submissions);
    }

    /**
     * Approves a submission and folds its changes into the character.
     */
    public function approve(Request $request, int $submission)
    {
        $model = $this->storyteldSubmission($request, $submission);

        $attributes = $this->validateLegacy($request->all(), [
            'current_json' => 'sometimes|nullable|array',
            'note' => 'sometimes|nullable|string',
        ]);

        if (array_key_exists('note', $attributes)) {
            $model->note = $attributes['note'];
        }

        $this->service->approve($model, $request->user(), $attributes['current_json'] ?? null);

        $character = $model->character->fresh();

        return response()->json([
            'submission' => (new CharacterSubmissionResource($model->fresh()))->toArray($request),
            'character' => (new CharacterResource($character))
                ->full()
                ->budget($this->budget->summary($character))
                ->toArray($request),
        ]);
    }

    /**
     * Rejects a submission with a note, returning it to the player.
     */
    public function reject(Request $request, int $submission)
    {
        $model = $this->storyteldSubmission($request, $submission);

        $attributes = $this->validateLegacy($request->all(), [
            'note' => 'required|string|min:1',
        ]);

        $this->service->reject($model, $request->user(), $attributes['note']);

        return new CharacterSubmissionResource($model);
    }

    /**
     * Downtime is always filed against the game's current cycle; other types
     * take whatever cycle the client tagged them with (a label only).
     */
    private function resolveCycle(Character $character, array $attributes): ?int
    {
        $cycleId = $attributes['cycle_id'] ?? null;

        if ($cycleId === null && ($attributes['type'] ?? null) === 'downtime' && $character->org_unit_id !== null) {
            return Cycle::current($character->org_unit_id)?->id;
        }

        if ($cycleId !== null) {
            $cycle = Cycle::find($cycleId);
            if ($cycle === null) {
                throw new UserException('Cycle not found', 404);
            }
            if ($cycle->org_unit_id !== $character->org_unit_id) {
                throw new UserException('Cycle belongs to another game', 400);
            }
        }

        return $cycleId;
    }

    private function submissionOf(Character $character, int $sid): CharacterSubmission
    {
        $submission = CharacterSubmission::where('character_id', $character->id)->find($sid);

        if ($submission === null) {
            throw new UserException('Submission not found', 404);
        }
        if ($submission->type === 'storyteller') {
            throw new UserException('Storyteller submissions cannot be edited', 403);
        }

        return $submission;
    }

    /**
     * The `storyteller` middleware already resolved and authorized it.
     */
    private function storyteldSubmission(Request $request, int $id): CharacterSubmission
    {
        $submission = $request->attributes->get('storyteller.submission');

        if ($submission instanceof CharacterSubmission && $submission->id === $id) {
            return $submission;
        }

        $submission = CharacterSubmission::with('character')->find($id);
        if ($submission === null) {
            throw new UserException('Submission not found', 404);
        }

        return $submission;
    }
}
