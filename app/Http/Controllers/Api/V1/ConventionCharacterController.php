<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\UserException;
use App\Http\Controllers\Concerns\ValidatesLegacy;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\OrgUnit;
use App\Services\AuditService;
use App\Support\ConventionCharacter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Convention characters (sporebound-character "convention-plan.md"): one-shot
 * pre-gens a convention venue's storyteller team imports, edits and prints.
 *
 * Every route is behind the `storyteller` middleware (character_approve over
 * the venue or an ancestor), and refuses venues that aren't convention venues.
 * Edits are plain whole-document PUTs with optimistic locking on `updated_at`;
 * there are no submissions, cycles or players.
 */
class ConventionCharacterController extends Controller
{
    use ValidatesLegacy;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Every convention character in the venue, by sheet code. The roster gets a
     * summary (no prose); `?full=1` returns whole documents for export.
     */
    public function index(Request $request, int $unit): JsonResponse
    {
        $this->conventionUnit($unit);
        $full = $request->boolean('full');

        $rows = Character::convention()
            ->where('org_unit_id', $unit)
            ->orderBy('sheet_code')
            ->get()
            ->map(fn (Character $c) => $this->serialize($c, $full))
            ->all();

        return response()->json($rows);
    }

    /**
     * Creates one convention character (the roster's "New character").
     */
    public function store(Request $request, int $unit): JsonResponse
    {
        $this->conventionUnit($unit);
        $doc = ConventionCharacter::validate($request->input('character_json'));
        $this->assertSheetCodeFree($unit, $doc['sheetCode']);

        $character = new Character([
            'kind' => Character::KIND_CONVENTION,
            'name' => ConventionCharacter::displayName($doc),
            'sheet_code' => $doc['sheetCode'],
            'user_id' => null,
            'created_by' => $request->user()->id,
            'org_unit_id' => $unit,
            'character_json' => $doc,
            'current_json' => null,
        ]);
        $character->save();

        $this->audit->log('Created convention character', [$character], [], ['after' => $doc]);

        return response()->json($this->serialize($character, true), 201);
    }

    /**
     * Bulk upsert by sheet code, all or nothing. An existing sheet code is
     * skipped unless `overwrite` is set, so a re-import never silently
     * replaces storyteller edits.
     */
    public function import(Request $request, int $unit): JsonResponse
    {
        $this->conventionUnit($unit);

        $attributes = $this->validateLegacy($request->all(), [
            'characters' => 'present|array|max:500',
            'overwrite' => 'sometimes|boolean',
        ]);
        $overwrite = (bool) ($attributes['overwrite'] ?? false);

        $docs = [];
        $seen = [];
        foreach (array_values($request->input('characters', [])) as $i => $raw) {
            $doc = ConventionCharacter::validate($raw, "characters.{$i}: ");
            $key = mb_strtolower($doc['sheetCode']);
            if (isset($seen[$key])) {
                throw new UserException("Invalid data provided: sheet code {$doc['sheetCode']} appears twice in this import", 400);
            }
            $seen[$key] = true;
            $docs[] = $doc;
        }

        $result = ['created' => [], 'updated' => [], 'skipped' => []];

        DB::transaction(function () use ($docs, $unit, $overwrite, $request, &$result) {
            $existing = Character::where('org_unit_id', $unit)
                ->whereNotNull('sheet_code')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Character $c) => mb_strtolower($c->sheet_code));

            foreach ($docs as $doc) {
                $current = $existing->get(mb_strtolower($doc['sheetCode']));

                if ($current !== null && ! $current->isConvention()) {
                    throw new UserException("Sheet code {$doc['sheetCode']} belongs to a non-convention character", 409);
                }

                if ($current !== null && ! $overwrite) {
                    $result['skipped'][] = $doc['sheetCode'];

                    continue;
                }

                if ($current !== null) {
                    $current->fill([
                        'name' => ConventionCharacter::displayName($doc),
                        'sheet_code' => $doc['sheetCode'],
                        'character_json' => $doc,
                    ])->save();
                    $result['updated'][] = $doc['sheetCode'];

                    continue;
                }

                Character::create([
                    'kind' => Character::KIND_CONVENTION,
                    'name' => ConventionCharacter::displayName($doc),
                    'sheet_code' => $doc['sheetCode'],
                    'user_id' => null,
                    'created_by' => $request->user()->id,
                    'org_unit_id' => $unit,
                    'character_json' => $doc,
                    'current_json' => null,
                ]);
                $result['created'][] = $doc['sheetCode'];
            }
        });

        $this->audit->log('Imported convention characters', [['service' => 'user-hub', 'object' => 'org_units', 'objectId' => $unit]], [], [
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
        ]);

        return response()->json($result);
    }

    public function show(Request $request, int $character): JsonResponse
    {
        return response()->json($this->serialize($this->conventionCharacter($request, $character), true));
    }

    /**
     * Replaces the document. The client sends back the `updated_at` it loaded;
     * if the row has moved on since, someone else saved first and this is a 409.
     */
    public function update(Request $request, int $character): JsonResponse
    {
        $model = $this->conventionCharacter($request, $character);

        $attributes = $this->validateLegacy($request->all(), [
            'updated_at' => 'required|string',
        ]);
        $doc = ConventionCharacter::validate($request->input('character_json'));

        $this->assertNotStale($model, $attributes['updated_at']);
        if (mb_strtolower($doc['sheetCode']) !== mb_strtolower((string) $model->sheet_code)) {
            $this->assertSheetCodeFree($model->org_unit_id, $doc['sheetCode'], $model->id);
        }

        $before = ['updated_at' => $model->updated_at?->toJSON(), 'doc' => $model->character_json];

        $model->fill([
            'name' => ConventionCharacter::displayName($doc),
            'sheet_code' => $doc['sheetCode'],
            'character_json' => $doc,
        ]);
        // Always move updated_at, even for a no-op save, so the lock token a
        // second editor holds is invalidated by any save that "won".
        $model->updated_at = $this->nextTimestamp($model);
        $model->save();

        $this->audit->log('Edited convention character', [$model], [], [
            'before' => $before,
            'after' => ['updated_at' => $model->updated_at->toJSON(), 'doc' => $doc],
        ]);

        return response()->json($this->serialize($model, true));
    }

    /**
     * Hard delete: they're pre-gens, and a re-import restores one.
     */
    public function destroy(Request $request, int $character): JsonResponse
    {
        $model = $this->conventionCharacter($request, $character);
        $doc = $model->character_json;
        $model->delete();

        $this->audit->log('Deleted convention character', [$model], [], ['before' => $doc]);

        return response()->json(['success' => true]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function serialize(Character $c, bool $full): array
    {
        $doc = is_array($c->character_json) ? $c->character_json : [];

        return [
            'id' => $c->id,
            'kind' => $c->kind,
            'name' => $c->name,
            'sheet_code' => $c->sheet_code,
            'org_unit_id' => $c->org_unit_id,
            'created_by' => $c->created_by,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
            'full' => $full,
            'character_json' => $full ? $doc : ConventionCharacter::summary($doc),
        ];
    }

    private function conventionUnit(int $unit): OrgUnit
    {
        $model = OrgUnit::find($unit);
        if ($model === null) {
            throw new UserException('Org unit not found', 404);
        }
        if (! $model->isConvention()) {
            throw new UserException('This venue is not a convention venue', 400);
        }

        return $model;
    }

    /**
     * The route's character, already resolved and authorized by the
     * `storyteller` middleware, provided it is a convention character.
     */
    private function conventionCharacter(Request $request, int $id): Character
    {
        $character = $request->attributes->get('storyteller.character');
        if (! ($character instanceof Character) || $character->id !== $id) {
            $character = Character::find($id);
        }
        if ($character === null) {
            throw new UserException('Character not found', 404);
        }
        if (! $character->isConvention()) {
            throw new UserException('Not a convention character', 400);
        }

        return $character;
    }

    private function assertSheetCodeFree(int $unit, string $code, ?int $exceptId = null): void
    {
        $taken = Character::where('org_unit_id', $unit)
            ->where('sheet_code', $code)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw new UserException("Sheet code {$code} is already used in this venue", 409);
        }
    }

    private function assertNotStale(Character $model, string $loaded): void
    {
        try {
            $loadedAt = Carbon::parse($loaded);
        } catch (\Throwable) {
            throw new UserException('Invalid data provided: updated_at is not a date', 400);
        }

        if ($model->updated_at === null || $model->updated_at->getTimestamp() !== $loadedAt->getTimestamp()) {
            throw new UserException('This character was changed by someone else. Reload to see their changes.', 409);
        }
    }

    /**
     * Timestamps are stored to the second, so two saves in the same second
     * would leave the lock token unchanged. Step past the stored value.
     */
    private function nextTimestamp(Character $model): Carbon
    {
        $now = now()->startOfSecond();
        $prev = $model->updated_at;

        return $prev !== null && $now->lte($prev) ? $prev->copy()->addSecond() : $now;
    }
}
