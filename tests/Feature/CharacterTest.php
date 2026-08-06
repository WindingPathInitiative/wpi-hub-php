<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterSubmission;
use Database\Seeders\CharacterFixturesSeeder as F;
use Tests\CharacterTestCase;

/**
 * Player-side character CRUD plus the storyteller roster and anchor edits.
 */
class CharacterTest extends CharacterTestCase
{
    // --- GET /character ---------------------------------------------------

    public function test_index_requires_a_token(): void
    {
        $this->getJson('/v1/character')->assertStatus(403);
    }

    public function test_index_lists_only_my_active_characters(): void
    {
        $this->makeCharacter(['name' => 'Mine']);
        $this->makeCharacter(['name' => 'Retired', 'retired_at' => now()]);
        $this->makeCharacter(['name' => 'Theirs', 'user_id' => F::OTHER_PLAYER]);

        $res = $this->getJsonAs(F::PLAYER, '/v1/character')->assertOk();
        $this->assertSame(['Mine'], array_column($res->json(), 'name'));

        $res = $this->getJsonAs(F::PLAYER, '/v1/character?includeRetired=1')->assertOk();
        $this->assertSame(['Mine', 'Retired'], array_column($res->json(), 'name'));
    }

    // --- GET /character/{id} ----------------------------------------------

    public function test_show_is_scoped_to_the_owner_and_their_storyteller(): void
    {
        $character = $this->makeCharacter();

        $this->getJsonAs(F::OTHER_PLAYER, "/v1/character/{$character->id}")->assertStatus(403);
        $this->getJsonAs(F::OTHER_ST, "/v1/character/{$character->id}")->assertStatus(403);
        $this->getJsonAs(F::PLAYER, '/v1/character/9999')->assertStatus(404);
    }

    public function test_show_is_readable_by_a_storyteller_for_the_game(): void
    {
        $character = $this->makeCharacter();

        // The storyteller screens render the sheet, diff pending submissions
        // against it, and pick the Anchor an anchor edit targets.
        $this->getJsonAs(F::ST, "/v1/character/{$character->id}")
            ->assertOk()
            ->assertJsonStructure(['character_json', 'current_json', 'submissions', 'budget']);

        // Ancestor scoping applies here too.
        $this->getJsonAs(F::CHAPTER_ST, "/v1/character/{$character->id}")->assertOk();
    }

    public function test_show_returns_the_envelope_submissions_and_budget(): void
    {
        $character = $this->makeCharacter();
        $this->makeCycle(['improvements_granted' => 2]);
        $this->makeCycle(['name' => 'Not open yet', 'opens_at' => now()->addDay(), 'improvements_granted' => 2]);

        $res = $this->getJsonAs(F::PLAYER, "/v1/character/{$character->id}")->assertOk();

        $res->assertJsonStructure(['id', 'name', 'character_json', 'current_json', 'submissions', 'budget']);
        // Only the opened cycle counts toward the budget.
        $this->assertSame(2, $res->json('budget.granted'));
        $this->assertSame(2, $res->json('budget.remaining'));
        $this->assertSame(1, $res->json('budget.germ_cap'));
    }

    // --- POST /character --------------------------------------------------

    public function test_store_lifts_a_v1_export_into_the_v2_envelope(): void
    {
        $res = $this->asUser(F::PLAYER)->postJson('/v1/character', [
            'org_unit_id' => F::GAME,
            'character_json' => [
                'identity' => ['characterName' => 'Nicki Parker'],
                'clade' => 'dumpster',
                'attributes' => ['body' => 5],
            ],
        ])->assertStatus(201);

        $this->assertSame('Nicki Parker', $res->json('name'));
        $this->assertSame(2, $res->json('character_json.formatVersion'));
        $this->assertSame('dumpster', $res->json('character_json.starting.clade'));
        $this->assertSame([], $res->json('character_json.changes'));
        $this->assertArrayNotHasKey('identity', $res->json('character_json.starting'));
    }

    public function test_store_keeps_a_v2_envelope_as_is(): void
    {
        $res = $this->asUser(F::PLAYER)->postJson('/v1/character', [
            'name' => 'Nicki',
            'character_json' => [
                'formatVersion' => 2,
                'identity' => ['characterName' => 'Nicki'],
                'starting' => ['cladeId' => 'dumpster'],
                'changes' => [],
                'current' => ['cladeId' => 'dumpster'],
            ],
        ])->assertStatus(201);

        $this->assertSame('dumpster', $res->json('character_json.starting.cladeId'));
        // current is mirrored into the cache column.
        $this->assertSame('dumpster', $res->json('current_json.cladeId'));
    }

    public function test_store_validates(): void
    {
        $this->asUser(F::PLAYER)->postJson('/v1/character', [])->assertStatus(400);
        $this->asUser(F::PLAYER)->postJson('/v1/character', ['character_json' => ['a' => 1]])
            ->assertStatus(400); // no name anywhere
        $this->asUser(F::PLAYER)->postJson('/v1/character', [
            'name' => 'X', 'org_unit_id' => 9999, 'character_json' => ['a' => 1],
        ])->assertStatus(404);
    }

    public function test_store_allows_a_character_with_no_game(): void
    {
        $res = $this->asUser(F::PLAYER)->postJson('/v1/character', [
            'name' => 'Unassigned',
            'character_json' => ['clade' => 'dumpster'],
        ])->assertStatus(201);

        $this->assertNull($res->json('org_unit_id'));
    }

    // --- PUT /character/{id} ----------------------------------------------

    public function test_update_renames_and_assigns_a_game(): void
    {
        $character = $this->makeCharacter(['org_unit_id' => null]);

        $this->asUser(F::PLAYER)->putJson("/v1/character/{$character->id}", [
            'name' => 'Renamed',
            'org_unit_id' => F::GAME,
        ])->assertOk();

        $character->refresh();
        $this->assertSame('Renamed', $character->name);
        $this->assertSame(F::GAME, $character->org_unit_id);
    }

    public function test_update_will_not_move_a_character_with_pending_submissions(): void
    {
        $character = $this->makeCharacter();
        CharacterSubmission::create([
            'character_id' => $character->id,
            'type' => 'adjustment',
            'status' => 'submitted',
            'changes_json' => $this->changes(['adjust.face-cards']),
            'submitted_at' => now(),
        ]);

        $this->asUser(F::PLAYER)
            ->putJson("/v1/character/{$character->id}", ['org_unit_id' => F::OTHER_GAME])
            ->assertStatus(400);
    }

    public function test_update_is_owner_scoped(): void
    {
        $character = $this->makeCharacter();

        $this->asUser(F::OTHER_PLAYER)->putJson("/v1/character/{$character->id}", ['name' => 'Nope'])
            ->assertStatus(403);
    }

    // --- retire / unretire ------------------------------------------------

    public function test_owner_and_storyteller_can_retire_but_strangers_cannot(): void
    {
        $character = $this->makeCharacter();

        $this->asUser(F::OTHER_PLAYER)->putJson("/v1/character/{$character->id}/retire")->assertStatus(403);
        $this->asUser(F::OTHER_ST)->putJson("/v1/character/{$character->id}/retire")->assertStatus(403);

        $this->asUser(F::ST)->putJson("/v1/character/{$character->id}/retire")->assertOk();
        $this->assertNotNull($character->fresh()->retired_at);

        // Retiring twice is a no-op error; the owner can bring them back.
        $this->asUser(F::ST)->putJson("/v1/character/{$character->id}/retire")->assertStatus(400);
        $this->asUser(F::PLAYER)->putJson("/v1/character/{$character->id}/unretire")->assertOk();
        $this->assertNull($character->fresh()->retired_at);
    }

    // --- GET /org-unit/{unit}/characters ----------------------------------

    public function test_roster_needs_character_approve_over_the_game(): void
    {
        $this->makeCharacter(['name' => 'Nicki']);
        $this->makeCharacter(['name' => 'Gone', 'retired_at' => now()]);

        $this->getJsonAs(F::PLAYER, '/v1/org-unit/'.F::GAME.'/characters')->assertStatus(403);
        $this->getJsonAs(F::BYSTANDER, '/v1/org-unit/'.F::GAME.'/characters')->assertStatus(403);
        $this->getJsonAs(F::OTHER_ST, '/v1/org-unit/'.F::GAME.'/characters')->assertStatus(403);

        $res = $this->getJsonAs(F::ST, '/v1/org-unit/'.F::GAME.'/characters')->assertOk();
        $this->assertSame(['Nicki'], array_column($res->json(), 'name'));

        // The chapter above the game qualifies through the materialized path.
        $this->getJsonAs(F::CHAPTER_ST, '/v1/org-unit/'.F::GAME.'/characters')->assertOk();

        $res = $this->getJsonAs(F::ST, '/v1/org-unit/'.F::GAME.'/characters?includeRetired=1')->assertOk();
        $this->assertCount(2, $res->json());
    }

    // --- storyteller anchor edits -----------------------------------------

    public function test_storyteller_can_grant_an_anchor(): void
    {
        $character = $this->makeCharacter();

        $sheet = $this->sheet();
        $sheet['anchors'][] = ['anchorId' => 'a3', 'type' => 'group', 'name' => 'The Council', 'active' => true];

        $res = $this->asUser(F::ST)->postJson("/v1/character/{$character->id}/anchor", [
            'anchor' => ['type' => 'group', 'name' => 'The Council', 'tag' => 'underdog'],
            'current_json' => $sheet,
        ])->assertStatus(201);

        $submission = CharacterSubmission::find($res->json('submission_id'));
        $this->assertSame('storyteller', $submission->type);
        $this->assertSame('approved', $submission->status);
        $this->assertSame(F::ST, $submission->resolved_by);

        // The change is folded into the envelope and the sheet cache refreshed.
        $character->refresh();
        $this->assertCount(1, $character->character_json['changes']);
        $this->assertSame('storyteller.add-anchor', $character->character_json['changes'][0]['kind']);
        $this->assertCount(3, $character->current_json['anchors']);
    }

    public function test_storyteller_cannot_exceed_the_anchor_cap(): void
    {
        $sheet = $this->sheet();
        $sheet['anchors'][] = ['anchorId' => 'a3', 'type' => 'group', 'name' => 'Third', 'active' => true];
        $character = $this->makeCharacter(['current_json' => $sheet]);

        $this->asUser(F::ST)->postJson("/v1/character/{$character->id}/anchor", [
            'anchor' => ['type' => 'group', 'name' => 'Fourth'],
        ])->assertStatus(400);
    }

    public function test_storyteller_can_destroy_an_anchor(): void
    {
        $character = $this->makeCharacter();

        $this->asUser(F::ST)
            ->deleteJson("/v1/character/{$character->id}/anchor/a2", ['reason' => 'Killed off in play'])
            ->assertStatus(201);

        $change = $character->fresh()->character_json['changes'][0];
        $this->assertSame('storyteller.remove-anchor', $change['kind']);
        $this->assertSame('a2', $change['payload']['anchorId']);
        $this->assertSame('approved', $change['status']);
    }

    public function test_destroying_an_unknown_anchor_is_404(): void
    {
        $character = $this->makeCharacter();

        $this->asUser(F::ST)
            ->deleteJson("/v1/character/{$character->id}/anchor/a9", ['reason' => 'Nope'])
            ->assertStatus(404);
    }

    public function test_anchor_edits_need_a_storyteller_and_a_game(): void
    {
        $character = $this->makeCharacter();
        $unassigned = $this->makeCharacter(['org_unit_id' => null]);

        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/anchor", [
            'anchor' => ['name' => 'Mine'],
        ])->assertStatus(403);

        $this->asUser(F::ST)->postJson("/v1/character/{$unassigned->id}/anchor", [
            'anchor' => ['name' => 'Nowhere'],
        ])->assertStatus(403);
    }

    public function test_a_v1_character_is_normalized_before_changes_are_appended(): void
    {
        // Regression guard: the envelope is normalized on save, so a character
        // saved from a v1 export still has a changes array to append to.
        $character = Character::create([
            'name' => 'Legacy',
            'user_id' => F::PLAYER,
            'org_unit_id' => F::GAME,
            'character_json' => ['clade' => 'dumpster'],
        ]);

        $this->asUser(F::ST)->postJson("/v1/character/{$character->id}/anchor", [
            'anchor' => ['type' => 'group', 'name' => 'Late Addition'],
        ])->assertStatus(201);

        $envelope = $character->fresh()->character_json;
        $this->assertSame(2, $envelope['formatVersion']);
        $this->assertCount(1, $envelope['changes']);
    }
}
