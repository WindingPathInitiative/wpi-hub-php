<?php

namespace Tests\Feature;

use App\Models\CharacterSubmission;
use App\Services\SubmissionService;
use Database\Seeders\CharacterFixturesSeeder as F;
use Tests\CharacterTestCase;

/**
 * The submission lifecycle: draft -> submitted -> approved/rejected, and the
 * counts, caps and deadlines the server enforces on the way through.
 */
class SubmissionTest extends CharacterTestCase
{
    /**
     * Drafts a submission through the API and returns its id.
     */
    private function draft(int $characterId, string $type, array $changes, array $extra = []): int
    {
        return $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$characterId}/submission", array_merge([
                'type' => $type,
                'changes_json' => $changes,
            ], $extra))
            ->assertStatus(201)
            ->json('id');
    }

    // --- drafting ---------------------------------------------------------

    public function test_draft_create_edit_and_delete(): void
    {
        $character = $this->makeCharacter();
        $id = $this->draft($character->id, 'advancement', $this->changes(['advancement.card']));

        $this->assertSame('draft', CharacterSubmission::find($id)->status);

        $this->asUser(F::PLAYER)->putJson("/v1/character/{$character->id}/submission/{$id}", [
            'changes_json' => $this->changes(['advancement.skill']),
            'note' => 'Changed my mind',
        ])->assertOk();

        $submission = CharacterSubmission::find($id);
        $this->assertSame('advancement.skill', $submission->changes_json[0]['kind']);
        $this->assertSame('Changed my mind', $submission->note);

        $this->asUser(F::PLAYER)->deleteJson("/v1/character/{$character->id}/submission/{$id}")->assertOk();
        $this->assertNull(CharacterSubmission::find($id));
    }

    public function test_drafting_is_owner_scoped_and_type_checked(): void
    {
        $character = $this->makeCharacter();

        $this->asUser(F::OTHER_PLAYER)->postJson("/v1/character/{$character->id}/submission", [
            'type' => 'advancement',
            'changes_json' => $this->changes(['advancement.card']),
        ])->assertStatus(403);

        // Players may not author storyteller submissions.
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission", [
            'type' => 'storyteller',
            'changes_json' => [['kind' => 'storyteller.add-anchor', 'payload' => []]],
        ])->assertStatus(400);

        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission", [
            'type' => 'advancement',
            'changes_json' => [],
        ])->assertStatus(400);
    }

    public function test_a_retired_character_takes_no_submissions(): void
    {
        $character = $this->makeCharacter(['retired_at' => now()]);

        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission", [
            'type' => 'advancement',
            'changes_json' => $this->changes(['advancement.card']),
        ])->assertStatus(400);
    }

    // --- submitting: budget -----------------------------------------------

    public function test_submitting_needs_a_game(): void
    {
        $character = $this->makeCharacter(['org_unit_id' => null]);
        $id = $this->draft($character->id, 'advancement', $this->changes(['advancement.card']));

        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
            ->assertStatus(400);
    }

    public function test_advancements_are_capped_by_the_cycle_budget(): void
    {
        $this->makeCycle(['improvements_granted' => 2]);
        $character = $this->makeCharacter();

        $tooMany = $this->draft($character->id, 'advancement', $this->changes([
            'advancement.card', 'advancement.skill', 'advancement.card',
        ]));
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$tooMany}/submit")
            ->assertStatus(400);

        $this->asUser(F::PLAYER)->putJson("/v1/character/{$character->id}/submission/{$tooMany}", [
            'changes_json' => $this->changes(['advancement.card', 'advancement.skill']),
        ])->assertOk();

        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$tooMany}/submit")
            ->assertOk();
        $this->assertSame('submitted', CharacterSubmission::find($tooMany)->status);
    }

    public function test_pending_submissions_consume_the_budget_too(): void
    {
        $this->makeCycle(['improvements_granted' => 2]);
        $character = $this->makeCharacter();

        $first = $this->draft($character->id, 'advancement', $this->changes(['advancement.card', 'advancement.skill']));
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$first}/submit")->assertOk();

        $second = $this->draft($character->id, 'advancement', $this->changes(['advancement.card']));
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$second}/submit")
            ->assertStatus(400);
    }

    public function test_germ_advancements_cap_at_half_the_budget(): void
    {
        $this->makeCycle(['improvements_granted' => 2]);
        $character = $this->makeCharacter();

        $id = $this->draft($character->id, 'advancement', $this->changes(['advancement.germ', 'advancement.germ']));
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
            ->assertStatus(400);

        $this->asUser(F::PLAYER)->putJson("/v1/character/{$character->id}/submission/{$id}", [
            'changes_json' => $this->changes(['advancement.germ', 'advancement.card']),
        ])->assertOk();
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
            ->assertOk();
    }

    // --- submitting: VXP --------------------------------------------------

    public function test_a_vxp_purchase_funds_its_own_improvement(): void
    {
        $this->makeCycle(['improvements_granted' => 0]);
        $character = $this->makeCharacter();

        $id = $this->draft($character->id, 'advancement', $this->changes(['vxp.purchase', 'advancement.card']));
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
            ->assertOk();

        $budget = $this->getJsonAs(F::PLAYER, "/v1/character/{$character->id}")->json('budget');
        $this->assertSame(1, $budget['vxp_pending']);
        $this->assertSame(1, $budget['budget']);
        $this->assertSame(1, $budget['spent']);
    }

    public function test_only_one_vxp_purchase_per_character_per_year(): void
    {
        $this->makeCycle(['improvements_granted' => 2]);
        $character = $this->makeCharacter();

        $first = $this->draft($character->id, 'advancement', $this->changes(['vxp.purchase']));
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$first}/submit")->assertOk();

        $second = $this->draft($character->id, 'advancement', $this->changes(['vxp.purchase']));
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$second}/submit")
            ->assertStatus(400);

        // Next calendar year it is available again.
        $this->travelTo('2027-01-05 12:00:00');
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$second}/submit")
            ->assertOk();
    }

    public function test_the_yearly_vxp_limit_is_per_character(): void
    {
        $this->makeCycle(['improvements_granted' => 2]);
        $one = $this->makeCharacter(['name' => 'First']);
        $two = $this->makeCharacter(['name' => 'Second']);

        foreach ([$one, $two] as $character) {
            $id = $this->draft($character->id, 'advancement', $this->changes(['vxp.purchase']));
            $this->asUser(F::PLAYER)
                ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
                ->assertOk();
        }
    }

    // --- submitting: downtime ---------------------------------------------

    public function test_downtime_is_bound_to_the_current_cycle(): void
    {
        $old = $this->makeCycle(['name' => 'Game 1 -> 2', 'opens_at' => now()->subDays(30)]);
        $current = $this->makeCycle(['name' => 'Game 2 -> 3', 'opens_at' => now()->subDays(2)]);
        $character = $this->makeCharacter();

        $stale = $this->draft($character->id, 'downtime', $this->downtime(), [
            'cycle_id' => $old->id,
        ]);
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$stale}/submit")
            ->assertStatus(400);

        // Omitting the cycle defaults to the current one.
        $id = $this->draft($character->id, 'downtime', $this->downtime());
        $this->assertSame($current->id, CharacterSubmission::find($id)->cycle_id);
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
            ->assertOk();
    }

    public function test_downtime_closes_at_the_deadline(): void
    {
        $this->makeCycle(['downtime_due_at' => now()->addHour()]);
        $character = $this->makeCharacter();

        $id = $this->draft($character->id, 'downtime', $this->downtime());

        $this->travelTo(now()->addDays(2));
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
            ->assertStatus(400);
    }

    public function test_one_downtime_per_character_per_cycle(): void
    {
        $this->makeCycle();
        $character = $this->makeCharacter();

        $first = $this->draft($character->id, 'downtime', $this->downtime());
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$first}/submit")->assertOk();

        $second = $this->draft($character->id, 'downtime', $this->downtime());
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$second}/submit")
            ->assertStatus(400);
    }

    public function test_developing_an_anchor_respects_the_cap(): void
    {
        $this->makeCycle();
        $sheet = $this->sheet();
        $sheet['anchors'][] = ['anchorId' => 'a3', 'type' => 'group', 'name' => 'Third', 'active' => true];
        $character = $this->makeCharacter(['current_json' => $sheet]);

        $id = $this->draft($character->id, 'downtime', $this->downtime());
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
            ->assertStatus(400);
    }

    public function test_a_lost_anchor_frees_a_slot(): void
    {
        $this->makeCycle();
        $sheet = $this->sheet();
        $sheet['anchors'][] = ['anchorId' => 'a3', 'type' => 'group', 'name' => 'Destroyed', 'active' => false];
        $character = $this->makeCharacter(['current_json' => $sheet]);

        $id = $this->draft($character->id, 'downtime', $this->downtime());
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
            ->assertOk();
    }

    public function test_every_downtime_action_is_accepted(): void
    {
        $this->makeCycle();

        foreach (SubmissionService::DOWNTIME_ACTIONS as $action) {
            $character = $this->makeCharacter(['name' => "Filed {$action}"]);
            $id = $this->draft($character->id, 'downtime', $this->downtime($action));

            $this->asUser(F::PLAYER)
                ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
                ->assertOk();
        }
    }

    public function test_a_downtime_is_one_investigation_and_one_action(): void
    {
        $this->makeCycle();
        $character = $this->makeCharacter();

        $shapes = [
            // Two Actions.
            $this->changes(['downtime.investigation', 'downtime.protect-anchor', 'downtime.narrative-impact']),
            // No Investigation.
            $this->changes(['downtime.protect-anchor']),
            // Two Investigations. (The kinds are prefixes of one another, so
            // this also proves the shape check matches exactly.)
            $this->changes(['downtime.investigation', 'downtime.investigation', 'downtime.investigate']),
            // No Action.
            $this->changes(['downtime.investigation']),
        ];

        foreach ($shapes as $changes) {
            $id = $this->draft($character->id, 'downtime', $changes);
            $this->asUser(F::PLAYER)
                ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
                ->assertStatus(400);
        }
    }

    public function test_culturing_needs_a_germ_this_character_has_on_a_standing_anchor(): void
    {
        $this->makeCycle();
        $character = $this->makeCharacter();

        $notMine = $this->draft($character->id, 'downtime', $this->changes(['downtime.investigation']));
        $this->asUser(F::PLAYER)->putJson("/v1/character/{$character->id}/submission/{$notMine}", [
            'changes_json' => array_merge($this->changes(['downtime.investigation']), [
                ['kind' => 'downtime.culture-germ', 'payload' => ['germId' => 'eldritch-grace']],
            ]),
        ])->assertOk();
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$notMine}/submit")
            ->assertStatus(400);

        // The Anchor that Germ hung off was destroyed, so it is tethered to
        // nothing and there is no relationship left to invest in.
        $sheet = $this->sheet();
        $sheet['anchors'][0]['active'] = false;
        $sheet['germs'][0]['anchorId'] = null;
        $stranded = $this->makeCharacter(['name' => 'Stranded', 'current_json' => $sheet]);

        $id = $this->draft($stranded->id, 'downtime', $this->downtime('downtime.culture-germ'));
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$stranded->id}/submission/{$id}/submit")
            ->assertStatus(400);
    }

    public function test_downtime_kinds_are_refused_in_other_submission_types(): void
    {
        $this->makeCycle();
        $character = $this->makeCharacter();

        foreach (['advancement', 'adjustment', 'rewrite'] as $type) {
            $id = $this->draft($character->id, $type, $this->changes(['downtime.narrative-impact']));
            $this->asUser(F::PLAYER)
                ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
                ->assertStatus(400);
        }
    }

    // --- submitting: adjustments ------------------------------------------

    public function test_adjustments_need_no_budget_but_must_reference_real_germs(): void
    {
        $character = $this->makeCharacter();

        $ok = $this->draft($character->id, 'adjustment', $this->changes(['adjust.germ-assignment', 'adjust.face-cards']));
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$ok}/submit")
            ->assertOk();

        $bogusGerm = $this->draft($character->id, 'adjustment', [
            ['kind' => 'adjust.germ-assignment', 'payload' => ['germId' => 'not-mine', 'anchorId' => 'a1']],
        ]);
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$bogusGerm}/submit")
            ->assertStatus(400);

        $bogusAnchor = $this->draft($character->id, 'adjustment', [
            ['kind' => 'adjust.germ-assignment', 'payload' => ['germId' => 'shroom-shield', 'anchorId' => 'a9']],
        ]);
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$bogusAnchor}/submit")
            ->assertStatus(400);
    }

    public function test_change_kinds_must_match_the_submission_type(): void
    {
        $this->makeCycle();
        $character = $this->makeCharacter();

        $id = $this->draft($character->id, 'adjustment', $this->changes(['advancement.card']));
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
            ->assertStatus(400);
    }

    // --- rewrite ----------------------------------------------------------

    public function test_a_rewrite_rebases_the_advancement_spend(): void
    {
        $this->makeCycle(['improvements_granted' => 2]);
        $character = $this->makeCharacter();

        $spend = $this->draft($character->id, 'advancement', $this->changes(['advancement.card', 'advancement.skill']));
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$spend}/submit")->assertOk();

        // The rewrite re-authors both improvements, so it fits the same budget.
        $rewrite = $this->draft($character->id, 'rewrite', [[
            'kind' => 'rewrite',
            'payload' => [
                'starting' => ['cladeId' => 'sewer'],
                'advancements' => $this->changes(['advancement.card', 'advancement.skill']),
            ],
        ]]);
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$rewrite}/submit")
            ->assertOk();

        $budget = $this->getJsonAs(F::PLAYER, "/v1/character/{$character->id}")->json('budget');
        $this->assertSame(2, $budget['spent']);
    }

    public function test_a_rewrite_must_carry_a_starting_character(): void
    {
        $character = $this->makeCharacter();

        $id = $this->draft($character->id, 'rewrite', [['kind' => 'rewrite', 'payload' => ['advancements' => []]]]);
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$id}/submit")
            ->assertStatus(400);
    }

    // --- the storyteller queue --------------------------------------------

    public function test_queue_shows_pending_submissions_to_the_right_storyteller(): void
    {
        $this->makeCycle();
        $character = $this->makeCharacter();

        $this->draft($character->id, 'advancement', $this->changes(['advancement.card'])); // stays a draft
        $submitted = $this->draft($character->id, 'adjustment', $this->changes(['adjust.face-cards']));
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$submitted}/submit")->assertOk();

        $this->getJsonAs(F::PLAYER, '/v1/org-unit/'.F::GAME.'/submissions')->assertStatus(403);
        $this->getJsonAs(F::OTHER_ST, '/v1/org-unit/'.F::GAME.'/submissions')->assertStatus(403);

        $res = $this->getJsonAs(F::ST, '/v1/org-unit/'.F::GAME.'/submissions')->assertOk();
        $this->assertCount(1, $res->json());
        $this->assertSame($submitted, $res->json('0.id'));
        $this->assertSame('Nicki Parker', $res->json('0.character.name'));

        // "all" never exposes the player's drafts.
        $res = $this->getJsonAs(F::ST, '/v1/org-unit/'.F::GAME.'/submissions?status=all')->assertOk();
        $this->assertCount(1, $res->json());

        $this->getJsonAs(F::ST, '/v1/org-unit/'.F::GAME.'/submissions?status=bogus')->assertStatus(400);
    }

    // --- approval ---------------------------------------------------------

    public function test_approval_folds_the_changes_into_the_character(): void
    {
        $this->makeCycle(['improvements_granted' => 2]);
        $character = $this->makeCharacter();

        $id = $this->draft($character->id, 'advancement', $this->changes(['advancement.card']));
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$id}/submit")->assertOk();

        $sheet = $this->sheet();
        $sheet['attributes']['body'] = 6;

        $res = $this->asUser(F::ST)->putJson("/v1/submission/{$id}/approve", ['current_json' => $sheet])
            ->assertOk();

        $this->assertSame('approved', $res->json('submission.status'));
        $this->assertSame(6, $res->json('character.current_json.attributes.body'));

        $character->refresh();
        $this->assertCount(1, $character->character_json['changes']);
        $change = $character->character_json['changes'][0];
        $this->assertSame('advancement.card', $change['kind']);
        $this->assertSame('approved', $change['status']);
        $this->assertNotNull($change['id']);
        $this->assertNotNull($change['resolvedAt']);
        $this->assertSame(6, $character->character_json['current']['attributes']['body']);
        $this->assertSame(6, $character->current_json['attributes']['body']);
        $this->assertSame(F::ST, CharacterSubmission::find($id)->resolved_by);
    }

    public function test_approval_falls_back_to_the_sheet_sent_with_the_submission(): void
    {
        $this->makeCycle(['improvements_granted' => 2]);
        $character = $this->makeCharacter();

        $sheet = $this->sheet();
        $sheet['trainedSkills'][] = 'investigate';

        $id = $this->draft($character->id, 'advancement', $this->changes(['advancement.skill']), [
            'current_json' => $sheet,
        ]);
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$id}/submit")->assertOk();
        $this->asUser(F::ST)->putJson("/v1/submission/{$id}/approve")->assertOk();

        $this->assertContains('investigate', $character->fresh()->current_json['trainedSkills']);
    }

    public function test_approval_needs_the_right_storyteller_and_a_submitted_submission(): void
    {
        $this->makeCycle();
        $character = $this->makeCharacter();
        $id = $this->draft($character->id, 'adjustment', $this->changes(['adjust.face-cards']));

        // Still a draft.
        $this->asUser(F::ST)->putJson("/v1/submission/{$id}/approve")->assertStatus(400);

        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$id}/submit")->assertOk();

        $this->asUser(F::PLAYER)->putJson("/v1/submission/{$id}/approve")->assertStatus(403);
        $this->asUser(F::OTHER_ST)->putJson("/v1/submission/{$id}/approve")->assertStatus(403);
        $this->asUser(F::ST)->putJson('/v1/submission/9999/approve')->assertStatus(404);

        $this->asUser(F::CHAPTER_ST)->putJson("/v1/submission/{$id}/approve")->assertOk();
    }

    public function test_submissions_are_resolved_oldest_first(): void
    {
        $this->makeCycle(['improvements_granted' => 2]);
        $character = $this->makeCharacter();

        $first = $this->draft($character->id, 'advancement', $this->changes(['advancement.card']));
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$first}/submit")->assertOk();

        $second = $this->draft($character->id, 'adjustment', $this->changes(['adjust.face-cards']));
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$second}/submit")->assertOk();

        $this->asUser(F::ST)->putJson("/v1/submission/{$second}/approve")->assertStatus(400);
        $this->asUser(F::ST)->putJson("/v1/submission/{$second}/reject", ['note' => 'no'])->assertStatus(400);

        $this->asUser(F::ST)->putJson("/v1/submission/{$first}/approve")->assertOk();
        $this->asUser(F::ST)->putJson("/v1/submission/{$second}/approve")->assertOk();
    }

    // --- rejection --------------------------------------------------------

    public function test_rejection_returns_the_submission_for_editing(): void
    {
        $this->makeCycle(['improvements_granted' => 2]);
        $character = $this->makeCharacter();

        $id = $this->draft($character->id, 'advancement', $this->changes(['advancement.germ']));
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$id}/submit")->assertOk();

        $this->asUser(F::ST)->putJson("/v1/submission/{$id}/reject")->assertStatus(400); // note required
        $this->asUser(F::ST)->putJson("/v1/submission/{$id}/reject", ['note' => 'Pick an on-clade Germ'])->assertOk();

        $submission = CharacterSubmission::find($id);
        $this->assertSame('rejected', $submission->status);
        $this->assertSame('Pick an on-clade Germ', $submission->note);
        $this->assertSame('rejected', $submission->changes_json[0]['status']);
        // A rejected submission stops consuming budget.
        $this->assertCount(0, $character->fresh()->character_json['changes']);

        $this->asUser(F::PLAYER)->putJson("/v1/character/{$character->id}/submission/{$id}", [
            'changes_json' => $this->changes(['advancement.card']),
        ])->assertOk();
        $this->assertSame('draft', CharacterSubmission::find($id)->status);

        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$id}/submit")->assertOk();
    }

    public function test_a_rejected_submission_frees_its_budget(): void
    {
        $this->makeCycle(['improvements_granted' => 2]);
        $character = $this->makeCharacter();

        $first = $this->draft($character->id, 'advancement', $this->changes(['advancement.card', 'advancement.skill']));
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$first}/submit")->assertOk();
        $this->asUser(F::ST)->putJson("/v1/submission/{$first}/reject", ['note' => 'Redo this'])->assertOk();

        $second = $this->draft($character->id, 'advancement', $this->changes(['advancement.card', 'advancement.skill']));
        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission/{$second}/submit")
            ->assertOk();
    }
}
