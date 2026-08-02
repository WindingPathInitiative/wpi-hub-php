<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\UserException;
use App\Http\Controllers\Concerns\ValidatesLegacy;
use App\Http\Controllers\Controller;
use App\Http\Resources\CycleResource;
use App\Models\Character;
use App\Models\CharacterSubmission;
use App\Models\Cycle;
use App\Models\OrgUnit;
use App\Services\CharacterBudgetService;
use Illuminate\Http\Request;

/**
 * Cycles: the between-games periods a storyteller runs, each granting the
 * game's characters their improvements.
 *
 * Listing is open to any authenticated player (they need the budget and the
 * downtime deadline); creating and editing needs `character_approve` over the
 * org unit.
 */
class CycleController extends Controller
{
    use ValidatesLegacy;

    /**
     * A game's cycles, newest first.
     */
    public function index(Request $request, int $unit)
    {
        if (! OrgUnit::whereKey($unit)->exists()) {
            throw new UserException('Org unit not found', 404);
        }

        $cycles = Cycle::where('org_unit_id', $unit)
            ->orderByDesc('opens_at')
            ->orderByDesc('id')
            ->get();

        return CycleResource::collection($cycles);
    }

    /**
     * Adds a cycle to a game.
     */
    public function store(Request $request, int $unit)
    {
        if (! OrgUnit::whereKey($unit)->exists()) {
            throw new UserException('Org unit not found', 404);
        }

        $attributes = $this->validateLegacy($request->all(), [
            'name' => 'required|string|min:1',
            'improvements_granted' => 'sometimes|integer|min:0|max:20',
            'opens_at' => 'required|date',
            'downtime_due_at' => 'sometimes|nullable|date|after:opens_at',
        ]);

        $cycle = new Cycle([
            'org_unit_id' => $unit,
            'name' => $attributes['name'],
            'improvements_granted' => $attributes['improvements_granted'] ?? 2,
            'opens_at' => $attributes['opens_at'],
            'downtime_due_at' => $attributes['downtime_due_at'] ?? null,
        ]);
        $cycle->save();

        return response()->json((new CycleResource($cycle))->toArray($request), 201);
    }

    /**
     * Edits a cycle. `improvements_granted` may not drop below what the game's
     * characters have already had approved against it.
     */
    public function update(Request $request, int $cycle)
    {
        $model = $this->find($request, $cycle);

        $attributes = $this->validateLegacy($request->all(), [
            'name' => 'sometimes|string|min:1',
            'improvements_granted' => 'sometimes|integer|min:0|max:20',
            'opens_at' => 'sometimes|date',
            'downtime_due_at' => 'sometimes|nullable|date',
        ]);

        if (isset($attributes['improvements_granted'])) {
            $this->assertNotBelowSpend($model, (int) $attributes['improvements_granted']);
            $model->improvements_granted = (int) $attributes['improvements_granted'];
        }
        foreach (['name', 'opens_at'] as $field) {
            if (isset($attributes[$field])) {
                $model->{$field} = $attributes[$field];
            }
        }
        if (array_key_exists('downtime_due_at', $attributes)) {
            $model->downtime_due_at = $attributes['downtime_due_at'];
        }

        if ($model->downtime_due_at !== null && $model->downtime_due_at->lte($model->opens_at)) {
            throw new UserException('Invalid data provided: downtime deadline must follow the cycle opening', 400);
        }

        $model->save();

        return new CycleResource($model);
    }

    /**
     * Deletes a cycle. Blocked once submissions reference it — its improvements
     * are already spent and its downtime already filed.
     */
    public function destroy(Request $request, int $cycle)
    {
        $model = $this->find($request, $cycle);

        if (CharacterSubmission::where('cycle_id', $model->id)->exists()) {
            throw new UserException('Cycle has submissions and cannot be deleted', 400);
        }

        $model->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Lowering a cycle's grant must not put any character over budget.
     */
    private function assertNotBelowSpend(Cycle $cycle, int $granted): void
    {
        $drop = $cycle->improvements_granted - $granted;
        if ($drop <= 0 || ! $cycle->opens_at->lte(now())) {
            return;
        }

        $budget = app(CharacterBudgetService::class);

        foreach (Character::where('org_unit_id', $cycle->org_unit_id)->active()->get() as $character) {
            $summary = $budget->summary($character);
            if ($summary['spent'] > $summary['budget'] - $drop) {
                throw new UserException(
                    "Cannot lower this cycle's improvements: {$character->name} has already used {$summary['spent']}",
                    400
                );
            }
        }
    }

    /**
     * The `storyteller` middleware already resolved and authorized the cycle.
     */
    private function find(Request $request, int $id): Cycle
    {
        $cycle = $request->attributes->get('storyteller.cycle');

        if ($cycle instanceof Cycle && $cycle->id === $id) {
            return $cycle;
        }

        $cycle = Cycle::find($id);
        if ($cycle === null) {
            throw new UserException('Cycle not found', 404);
        }

        return $cycle;
    }
}
