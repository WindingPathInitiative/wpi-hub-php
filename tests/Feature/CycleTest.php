<?php

namespace Tests\Feature;

use App\Models\CharacterSubmission;
use App\Models\Cycle;
use Database\Seeders\CharacterFixturesSeeder as F;
use Tests\CharacterTestCase;

/**
 * Cycle CRUD: storytellers run the cycle, players read it.
 */
class CycleTest extends CharacterTestCase
{
    // --- GET /org-unit/{unit}/cycles --------------------------------------

    public function test_players_can_list_a_games_cycles(): void
    {
        $this->makeCycle(['name' => 'Game 1 -> 2']);
        $this->makeCycle(['name' => 'Game 2 -> 3', 'opens_at' => now()->subDay()]);

        $this->getJson('/v1/org-unit/'.F::GAME.'/cycles')->assertStatus(403);

        $res = $this->getJsonAs(F::PLAYER, '/v1/org-unit/'.F::GAME.'/cycles')->assertOk();
        $this->assertSame(['Game 2 -> 3', 'Game 1 -> 2'], array_column($res->json(), 'name'));
        $this->assertTrue($res->json('0.is_open'));
        $this->assertTrue($res->json('0.downtime_open'));

        $this->getJsonAs(F::PLAYER, '/v1/org-unit/9999/cycles')->assertStatus(404);
    }

    public function test_a_closed_downtime_window_is_reported(): void
    {
        $this->makeCycle(['downtime_due_at' => now()->subDay()]);

        $res = $this->getJsonAs(F::PLAYER, '/v1/org-unit/'.F::GAME.'/cycles')->assertOk();
        $this->assertTrue($res->json('0.is_open'));
        $this->assertFalse($res->json('0.downtime_open'));
    }

    // --- POST /org-unit/{unit}/cycle --------------------------------------

    public function test_creating_a_cycle_needs_character_approve(): void
    {
        $payload = ['name' => 'Game 1 -> 2', 'opens_at' => '2026-06-01 18:00:00'];

        $this->asUser(F::PLAYER)->postJson('/v1/org-unit/'.F::GAME.'/cycle', $payload)->assertStatus(403);
        $this->asUser(F::OTHER_ST)->postJson('/v1/org-unit/'.F::GAME.'/cycle', $payload)->assertStatus(403);

        $res = $this->asUser(F::ST)->postJson('/v1/org-unit/'.F::GAME.'/cycle', $payload)->assertStatus(201);
        $this->assertSame(2, $res->json('improvements_granted')); // default
        $this->assertSame(F::GAME, $res->json('org_unit_id'));
    }

    public function test_game_one_can_grant_nothing(): void
    {
        $res = $this->asUser(F::ST)->postJson('/v1/org-unit/'.F::GAME.'/cycle', [
            'name' => 'Game 1',
            'improvements_granted' => 0,
            'opens_at' => '2026-05-01 18:00:00',
        ])->assertStatus(201);

        $this->assertSame(0, $res->json('improvements_granted'));
    }

    public function test_cycle_validation(): void
    {
        $this->asUser(F::ST)->postJson('/v1/org-unit/'.F::GAME.'/cycle', ['opens_at' => 'now'])
            ->assertStatus(400); // no name
        $this->asUser(F::ST)->postJson('/v1/org-unit/'.F::GAME.'/cycle', [
            'name' => 'Bad window',
            'opens_at' => '2026-06-01 18:00:00',
            'downtime_due_at' => '2026-05-01 18:00:00',
        ])->assertStatus(400);
    }

    // --- PUT /cycle/{id} --------------------------------------------------

    public function test_update_edits_the_window_and_grant(): void
    {
        $cycle = $this->makeCycle();

        $this->asUser(F::ST)->putJson("/v1/cycle/{$cycle->id}", [
            'name' => 'Renamed',
            'improvements_granted' => 3,
            'downtime_due_at' => '2026-06-20 23:59:00',
        ])->assertOk();

        $cycle->refresh();
        $this->assertSame('Renamed', $cycle->name);
        $this->assertSame(3, $cycle->improvements_granted);
    }

    public function test_update_will_not_strand_already_spent_improvements(): void
    {
        $cycle = $this->makeCycle(['improvements_granted' => 2]);
        $character = $this->makeCharacter();

        CharacterSubmission::create([
            'character_id' => $character->id,
            'cycle_id' => $cycle->id,
            'type' => 'advancement',
            'status' => 'approved',
            'changes_json' => $this->changes(['advancement.card', 'advancement.skill']),
            'submitted_at' => now(),
            'resolved_at' => now(),
        ]);

        $this->asUser(F::ST)->putJson("/v1/cycle/{$cycle->id}", ['improvements_granted' => 1])
            ->assertStatus(400);

        // Raising it is always fine.
        $this->asUser(F::ST)->putJson("/v1/cycle/{$cycle->id}", ['improvements_granted' => 4])->assertOk();
    }

    public function test_update_needs_a_storyteller_for_that_game(): void
    {
        $cycle = $this->makeCycle();

        $this->asUser(F::OTHER_ST)->putJson("/v1/cycle/{$cycle->id}", ['name' => 'Nope'])->assertStatus(403);
        $this->asUser(F::ST)->putJson('/v1/cycle/9999', ['name' => 'Nope'])->assertStatus(404);
    }

    // --- DELETE /cycle/{id} -----------------------------------------------

    public function test_delete_is_blocked_once_submissions_reference_the_cycle(): void
    {
        $cycle = $this->makeCycle();
        $character = $this->makeCharacter();

        $this->asUser(F::ST)->deleteJson("/v1/cycle/{$cycle->id}")->assertOk();
        $this->assertNull(Cycle::find($cycle->id));

        $kept = $this->makeCycle();
        CharacterSubmission::create([
            'character_id' => $character->id,
            'cycle_id' => $kept->id,
            'type' => 'advancement',
            'status' => 'draft',
            'changes_json' => $this->changes(['advancement.card']),
        ]);

        $this->asUser(F::ST)->deleteJson("/v1/cycle/{$kept->id}")->assertStatus(400);
        $this->assertNotNull(Cycle::find($kept->id));
    }
}
