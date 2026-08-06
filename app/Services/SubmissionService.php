<?php

namespace App\Services;

use App\Events\DowntimeSubmitted;
use App\Exceptions\UserException;
use App\Models\Character;
use App\Models\CharacterSubmission;
use App\Models\Cycle;
use App\Models\User;
use App\Support\CharacterEnvelope;
use Illuminate\Support\Facades\DB;

/**
 * Validation and resolution of character submissions.
 *
 * Phase 1 splits the rules in two: deep legality (is this a legal germ for this
 * clade?) stays in the TypeScript rules engine plus human ST review, while the
 * server enforces the things a client must not be trusted with — ownership,
 * counts, caps, deadlines, and ordering. Everything here is re-checked at
 * approval time, because the world (cycles, other submissions) moves between
 * submit and approve.
 */
class SubmissionService
{
    /** Sporebound caps a character at three active Anchors. */
    public const MAX_ACTIVE_ANCHORS = 3;

    /**
     * Change kinds each submission type may carry.
     *
     * @var array<string, string[]>
     */
    public const KINDS = [
        'advancement' => ['advancement.card', 'advancement.skill', 'advancement.germ', 'vxp.purchase'],
        'downtime' => [
            'downtime.investigation',
            'downtime.investigate',
            'downtime.develop-anchor',
            'downtime.protect-anchor',
            'downtime.undermine-anchor',
            'downtime.destroy-anchor',
            'downtime.narrative-impact',
            'downtime.culture-germ',
        ],
        'adjustment' => ['adjust.germ-assignment', 'adjust.face-cards'],
        'rewrite' => ['rewrite'],
        'storyteller' => ['storyteller.add-anchor', 'storyteller.remove-anchor'],
    ];

    /**
     * "Between games, you gain a single Investigation and a single Downtime
     * Action" — these are the Actions. Mirrors DOWNTIME_ACTION_KINDS in
     * character-state.models.ts.
     *
     * @var string[]
     */
    public const DOWNTIME_ACTIONS = [
        'downtime.investigate',
        'downtime.develop-anchor',
        'downtime.protect-anchor',
        'downtime.undermine-anchor',
        'downtime.destroy-anchor',
        'downtime.narrative-impact',
        'downtime.culture-germ',
    ];

    public function __construct(private readonly CharacterBudgetService $budget) {}

    /**
     * Full check of a submission against its character. Throws on the first
     * failure; callers run it on submit and again on approve.
     */
    public function validate(Character $character, CharacterSubmission $submission): void
    {
        if ($character->isRetired()) {
            throw new UserException('Character is retired', 400);
        }
        if ($character->org_unit_id === null) {
            throw new UserException('Character must be assigned to a game before it can be submitted', 400);
        }

        $this->validateKinds($submission);
        $this->validateBudget($character, $submission);
        $this->validateVxp($character, $submission);

        match ($submission->type) {
            'downtime' => $this->validateDowntime($character, $submission),
            'adjustment' => $this->validateAdjustment($character, $submission),
            'rewrite' => $this->validateRewrite($submission),
            default => null,
        };

        $this->validateAnchorCap($character, $submission);
    }

    /**
     * Every change carries a kind this submission type allows, and a payload.
     */
    private function validateKinds(CharacterSubmission $submission): void
    {
        $changes = $submission->changes_json ?? [];

        if (! is_array($changes) || $changes === []) {
            throw new UserException('Submission contains no changes', 400);
        }

        $allowed = self::KINDS[$submission->type] ?? [];

        foreach ($changes as $change) {
            if (! is_array($change) || ! isset($change['kind'])) {
                throw new UserException('Each change requires a kind', 400);
            }
            if (! in_array($change['kind'], $allowed, true)) {
                throw new UserException(
                    "Change kind {$change['kind']} is not allowed in a {$submission->type} submission",
                    400
                );
            }
            if (isset($change['payload']) && ! is_array($change['payload'])) {
                throw new UserException('Change payload must be an object', 400);
            }
        }
    }

    /**
     * Advancement count against the cycle budget, and the germ half-cap.
     */
    private function validateBudget(Character $character, CharacterSubmission $submission): void
    {
        $summary = $this->budget->summary($character, $submission);

        if ($summary['spent'] > $summary['budget']) {
            throw new UserException(
                "Advancement budget exceeded: {$summary['spent']} improvements used of {$summary['budget']} available",
                400
            );
        }

        if ($summary['germ_spent'] > $summary['germ_cap']) {
            throw new UserException(
                "Germ advancements exceed the half-budget cap: {$summary['germ_spent']} of {$summary['germ_cap']} allowed",
                400
            );
        }
    }

    /**
     * At most one VXP purchase per character per calendar year; the ST verifies
     * the actual 20 VXP spend out of band when approving.
     */
    private function validateVxp(Character $character, CharacterSubmission $submission): void
    {
        $purchases = $submission->changesOfKind('vxp.purchase');
        if ($purchases === []) {
            return;
        }
        if (count($purchases) > 1) {
            throw new UserException('Only one VXP improvement purchase per year', 400);
        }

        $year = ($submission->submitted_at ?? now())->year;

        if (in_array($year, $this->budget->vxpPurchaseYears($character, $submission), true)) {
            throw new UserException("A VXP improvement has already been purchased for {$year}", 400);
        }
    }

    /**
     * Downtime is bound to the game's current cycle and its deadline, a
     * character gets one downtime submission per cycle, and that submission
     * carries one Investigation and one Downtime Action.
     */
    private function validateDowntime(Character $character, CharacterSubmission $submission): void
    {
        $current = Cycle::current($character->org_unit_id);

        if ($current === null) {
            throw new UserException('This game has no open cycle', 400);
        }
        if ($submission->cycle_id !== $current->id) {
            throw new UserException('Downtime may only be submitted for the current cycle', 400);
        }
        if (! $current->downtimeIsOpen()) {
            throw new UserException('The downtime deadline for this cycle has passed', 400);
        }

        $existing = CharacterSubmission::query()
            ->where('character_id', $character->id)
            ->where('type', 'downtime')
            ->where('cycle_id', $current->id)
            ->committed()
            ->where('id', '!=', $submission->id ?? 0)
            ->exists();

        if ($existing) {
            throw new UserException('Downtime has already been submitted for this cycle', 400);
        }

        $this->validateDowntimeShape($submission);
        $this->validateCultureGerm($character, $submission);
    }

    /**
     * One Investigation and one Downtime Action, no more and no less. The
     * per-cycle cap above only stops a second *submission*; this is what stops
     * one submission taking two Actions.
     */
    private function validateDowntimeShape(CharacterSubmission $submission): void
    {
        $kinds = array_map(
            fn ($change) => is_array($change) ? (string) ($change['kind'] ?? '') : '',
            $submission->changes_json ?? []
        );

        $investigations = count(array_filter($kinds, fn ($k) => $k === 'downtime.investigation'));
        $actions = count(array_filter($kinds, fn ($k) => in_array($k, self::DOWNTIME_ACTIONS, true)));

        if ($investigations !== 1) {
            throw new UserException('A downtime carries exactly one Investigation', 400);
        }
        if ($actions !== 1) {
            throw new UserException('A downtime carries exactly one Downtime Action', 400);
        }
    }

    /**
     * Culturing must name a germ the character has that is tethered to an
     * Anchor still standing — an untethered germ is no relationship to invest
     * in. Skipped when no resolved sheet has been cached yet.
     */
    private function validateCultureGerm(Character $character, CharacterSubmission $submission): void
    {
        $changes = $this->changesWithKind($submission, 'downtime.culture-germ');
        if ($changes === []) {
            return;
        }

        $sheet = $character->resolved();
        if ($sheet === null) {
            return;
        }

        $activeAnchorIds = array_column($this->activeAnchors($sheet), 'anchorId');

        foreach ($changes as $change) {
            $germId = $change['payload']['germId'] ?? null;
            $germ = null;

            foreach ($sheet['germs'] ?? [] as $candidate) {
                if (is_array($candidate) && ($candidate['id'] ?? null) === $germId) {
                    $germ = $candidate;
                    break;
                }
            }

            if ($germId === null || $germ === null) {
                throw new UserException('Germ is not on this character', 400);
            }
            if (! in_array($germ['anchorId'] ?? null, $activeAnchorIds, true)) {
                throw new UserException('Germ is not tethered to an active Anchor', 400);
            }
        }
    }

    /**
     * Changes of exactly this kind. `changesOfKind` matches on prefix, which
     * would read a `downtime.investigation` as a `downtime.investigate`.
     *
     * @return array<int, array>
     */
    private function changesWithKind(CharacterSubmission $submission, string $kind): array
    {
        return array_values(array_filter(
            $submission->changes_json ?? [],
            fn ($c) => is_array($c) && ($c['kind'] ?? null) === $kind
        ));
    }

    /**
     * Germ reassignment must name a germ the character has and an Anchor that is
     * still active. Skipped when no resolved sheet has been cached yet.
     */
    private function validateAdjustment(Character $character, CharacterSubmission $submission): void
    {
        $sheet = $character->resolved();
        if ($sheet === null) {
            return;
        }

        $germIds = array_column($sheet['germs'] ?? [], 'id');
        $activeAnchorIds = array_column($this->activeAnchors($sheet), 'anchorId');

        foreach ($submission->changesOfKind('adjust.germ-assignment') as $change) {
            $germId = $change['payload']['germId'] ?? null;
            $anchorId = $change['payload']['anchorId'] ?? null;

            if ($germId === null || ! in_array($germId, $germIds, true)) {
                throw new UserException('Germ is not on this character', 400);
            }
            if ($anchorId !== null && ! in_array($anchorId, $activeAnchorIds, true)) {
                throw new UserException('Anchor is not active on this character', 400);
            }
        }
    }

    /**
     * A rewrite is a single change carrying the replacement starting character.
     */
    private function validateRewrite(CharacterSubmission $submission): void
    {
        $rewrites = $submission->changesOfKind('rewrite');

        if (count($rewrites) !== 1) {
            throw new UserException('A rewrite submission must contain exactly one rewrite change', 400);
        }
        if (empty($rewrites[0]['payload']['starting'])) {
            throw new UserException('A rewrite must include the new starting character', 400);
        }
    }

    /**
     * Anchors gained here plus those already active (including ones waiting in
     * other pending submissions) must not exceed the cap.
     */
    private function validateAnchorCap(Character $character, CharacterSubmission $submission): void
    {
        $gained = count($submission->changesOfKind('downtime.develop-anchor'))
            + count($submission->changesOfKind('storyteller.add-anchor'));

        if ($gained === 0) {
            return;
        }

        $sheet = $character->resolved();
        $active = $sheet === null ? 0 : count($this->activeAnchors($sheet));

        $inFlight = CharacterSubmission::query()
            ->where('character_id', $character->id)
            ->pending()
            ->where('id', '!=', $submission->id ?? 0)
            ->get()
            ->sum(fn (CharacterSubmission $s) => count($s->changesOfKind('downtime.develop-anchor'))
                + count($s->changesOfKind('storyteller.add-anchor')));

        if ($active + $inFlight + $gained > self::MAX_ACTIVE_ANCHORS) {
            throw new UserException(
                'A character may not have more than '.self::MAX_ACTIVE_ANCHORS.' active Anchors',
                400
            );
        }
    }

    /**
     * @return array<int, array>
     */
    private function activeAnchors(array $sheet): array
    {
        return array_values(array_filter(
            $sheet['anchors'] ?? [],
            fn ($a) => is_array($a) && ($a['active'] ?? true)
        ));
    }

    /**
     * Moves a draft to submitted after validating it.
     */
    public function submit(Character $character, CharacterSubmission $submission): CharacterSubmission
    {
        if (! $submission->isEditable()) {
            throw new UserException('Only a draft or rejected submission can be submitted', 400);
        }

        $submission->status = 'submitted';
        $submission->submitted_at = now();
        $submission->resolved_at = null;
        $submission->resolved_by = null;

        $this->validate($character, $submission);

        $submission->changes_json = CharacterEnvelope::stamp(
            $submission->changes_json ?? [],
            'submitted',
            $submission->cycle_id,
            $submission->submitted_at->toIso8601String(),
            null,
            $submission->note,
        );
        $submission->save();

        // Both routes into `submitted` come through here — a first filing and a
        // resubmit after a rejection — so this is where the record goes out to
        // the player and their storyteller.
        if ($submission->type === 'downtime') {
            $submission->setRelation('character', $character);
            DowntimeSubmitted::dispatch($submission);
        }

        return $submission;
    }

    /**
     * Approves a submission: re-validates it, folds its changes into the
     * character envelope, and refreshes the cached sheet.
     *
     * @param  ?array  $current  the resolved sheet the ST client replayed
     */
    public function approve(CharacterSubmission $submission, User $storyteller, ?array $current = null): CharacterSubmission
    {
        $character = $submission->character;
        $this->assertResolvable($submission);
        $this->validate($character, $submission);

        return DB::transaction(function () use ($submission, $character, $storyteller, $current) {
            $resolvedAt = now();
            $current ??= $submission->current_json;

            $submission->changes_json = CharacterEnvelope::stamp(
                $submission->changes_json ?? [],
                'approved',
                $submission->cycle_id,
                $submission->submitted_at?->toIso8601String(),
                $resolvedAt->toIso8601String(),
            );
            $submission->status = 'approved';
            $submission->resolved_at = $resolvedAt;
            $submission->resolved_by = $storyteller->id;
            $submission->save();

            $character->character_json = CharacterEnvelope::withApproved(
                $character->character_json ?? [],
                $submission->changes_json,
                $current,
            );
            if ($current !== null) {
                $character->current_json = $current;
            }
            $character->save();

            return $submission;
        });
    }

    /**
     * Rejects a submission, returning it to the player for edit + resubmit.
     */
    public function reject(CharacterSubmission $submission, User $storyteller, string $note): CharacterSubmission
    {
        $this->assertResolvable($submission);

        $resolvedAt = now();
        $submission->changes_json = CharacterEnvelope::stamp(
            $submission->changes_json ?? [],
            'rejected',
            $submission->cycle_id,
            $submission->submitted_at?->toIso8601String(),
            $resolvedAt->toIso8601String(),
            $note,
        );
        $submission->status = 'rejected';
        $submission->note = $note;
        $submission->resolved_at = $resolvedAt;
        $submission->resolved_by = $storyteller->id;
        $submission->save();

        return $submission;
    }

    /**
     * A character's submissions are resolved oldest-first: later ones were
     * authored against a sheet that assumes the earlier ones landed.
     */
    private function assertResolvable(CharacterSubmission $submission): void
    {
        if ($submission->status !== 'submitted') {
            throw new UserException('Only a submitted submission can be resolved', 400);
        }

        $oldest = CharacterSubmission::query()
            ->where('character_id', $submission->character_id)
            ->pending()
            ->orderBy('id')
            ->first();

        if ($oldest !== null && $oldest->id !== $submission->id) {
            throw new UserException(
                "Submission {$oldest->id} was submitted first and must be resolved before this one",
                400
            );
        }
    }
}
