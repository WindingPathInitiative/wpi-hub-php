<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterSubmission;
use App\Models\Cycle;
use Illuminate\Support\Collection;

/**
 * Improvement budget accounting.
 *
 * Budget = the improvements granted by every *opened* cycle of the character's
 * org unit, plus one per VXP purchase. It deliberately ignores attendance, so a
 * character created mid-season starts with the same budget as everyone else.
 *
 * Spend is counted over the character's committed submissions (submitted +
 * approved — drafts cost nothing until the player commits them, rejections cost
 * nothing at all). Germ advancements additionally cap at half the budget.
 *
 * A rewrite re-authors the character's advancements: an approved or pending
 * rewrite resets the running spend to the advancements embedded in it, and only
 * later submissions add on top. VXP purchases survive a rewrite — the 20 VXP
 * were really spent — so they are counted across the whole history.
 */
class CharacterBudgetService
{
    /**
     * @param  ?CharacterSubmission  $pending  a submission being validated that
     *                                         is not committed yet; counted as if it were
     * @return array{granted:int, vxp_approved:int, vxp_pending:int, budget:int, spent:int, remaining:int, germ_spent:int, germ_cap:int}
     */
    public function summary(Character $character, ?CharacterSubmission $pending = null): array
    {
        $granted = $this->granted($character);
        $submissions = $this->relevantSubmissions($character, $pending);

        $vxpApproved = 0;
        $vxpPending = 0;
        foreach ($submissions as $submission) {
            $count = count($submission->changesOfKind('vxp.purchase'));
            if ($submission->status === 'approved') {
                $vxpApproved += $count;
            } else {
                $vxpPending += $count;
            }
        }

        // Pending purchases spend against the same submission that uses them,
        // so they have to count here or a "buy + spend" submission never fits.
        $budget = $granted + $vxpApproved + $vxpPending;

        [$spent, $germSpent] = $this->countAdvancements($submissions);

        return [
            'granted' => $granted,
            'vxp_approved' => $vxpApproved,
            'vxp_pending' => $vxpPending,
            'budget' => $budget,
            'spent' => $spent,
            'remaining' => $budget - $spent,
            'germ_spent' => $germSpent,
            'germ_cap' => intdiv($budget, 2),
        ];
    }

    /**
     * Improvements granted by the character's game so far.
     */
    public function granted(Character $character): int
    {
        if ($character->org_unit_id === null) {
            return 0;
        }

        return (int) Cycle::query()
            ->where('org_unit_id', $character->org_unit_id)
            ->opened()
            ->sum('improvements_granted');
    }

    /**
     * Calendar years in which the character already has a VXP purchase
     * (committed ones only — a rejected purchase frees the year up again).
     *
     * @return int[]
     */
    public function vxpPurchaseYears(Character $character, ?CharacterSubmission $exclude = null): array
    {
        $years = [];

        foreach ($this->relevantSubmissions($character, null) as $submission) {
            if ($exclude !== null && $submission->id === $exclude->id) {
                continue;
            }
            foreach ($submission->changesOfKind('vxp.purchase') as $ignored) {
                $years[] = ($submission->submitted_at ?? $submission->created_at ?? now())->year;
            }
        }

        return array_values(array_unique($years));
    }

    /**
     * Committed submissions oldest-first, with $pending appended when it is not
     * already among them.
     *
     * @return Collection<int, CharacterSubmission>
     */
    private function relevantSubmissions(Character $character, ?CharacterSubmission $pending): Collection
    {
        $submissions = CharacterSubmission::query()
            ->where('character_id', $character->id)
            ->committed()
            ->orderBy('id')
            ->get();

        if ($pending !== null && ! $submissions->contains(fn ($s) => $s->id === $pending->id)) {
            $submissions->push($pending);
        }

        return $submissions;
    }

    /**
     * Total and germ-only advancement counts, honouring rewrite resets.
     *
     * @param  Collection<int, CharacterSubmission>  $submissions
     * @return array{0:int, 1:int}
     */
    private function countAdvancements(Collection $submissions): array
    {
        $total = 0;
        $germs = 0;

        foreach ($submissions as $submission) {
            $rewrites = $submission->changesOfKind('rewrite');

            if ($rewrites !== []) {
                // The rewrite subsumes everything advancement-shaped before it.
                $total = 0;
                $germs = 0;
                foreach ($rewrites as $rewrite) {
                    foreach ($rewrite['payload']['advancements'] ?? [] as $change) {
                        if (! is_array($change) || ! str_starts_with((string) ($change['kind'] ?? ''), 'advancement.')) {
                            continue;
                        }
                        $total++;
                        if ($change['kind'] === 'advancement.germ') {
                            $germs++;
                        }
                    }
                }

                continue;
            }

            $advancements = $submission->changesOfKind('advancement.');
            $total += count($advancements);
            $germs += count(array_filter($advancements, fn ($c) => $c['kind'] === 'advancement.germ'));
        }

        return [$total, $germs];
    }
}
