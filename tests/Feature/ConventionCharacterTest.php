<?php

namespace Tests\Feature;

use App\Models\Character;
use Database\Seeders\CharacterFixturesSeeder as F;
use Illuminate\Support\Facades\DB;
use Tests\CharacterTestCase;

/**
 * Convention characters: storyteller-managed pre-gens on a -CON- venue.
 */
class ConventionCharacterTest extends CharacterTestCase
{
    private const LIST = '/v1/org-unit/'.F::CON_GAME.'/convention-characters';

    private const IMPORT = '/v1/org-unit/'.F::CON_GAME.'/convention-characters/import';

    /** Holds character_approve on the Nation. */
    private const NATIONAL_ST = 50;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->insert([
            'id' => self::NATIONAL_ST, 'portalID' => null, 'firstName' => 'National', 'lastName' => 'Teller',
            'nickname' => 'National Teller', 'address' => null, 'email' => 'nationalteller@example.com',
            'membershipType' => 'Full', 'membershipNumber' => 'WPI20260050',
            'membershipExpiration' => '2027-01-01', 'orgUnit' => F::NATION,
        ]);
        DB::table('offices')->insert([
            'id' => 50, 'name' => 'National Storyteller', 'email' => null, 'type' => 'Primary',
            'parentOfficeID' => null, 'parentPath' => '50', 'parentOrgID' => F::NATION,
            'userID' => self::NATIONAL_ST, 'roles' => json_encode(['character_approve']),
        ]);
    }

    private function doc(array $overrides = []): array
    {
        return array_merge([
            'format' => 'convention',
            'formatVersion' => 1,
            'sheetCode' => 'THM 1',
            'nickname' => 'Spin',
            'fullName' => 'D “Spin” Wilson',
            'cladeId' => 'treehugger',
            'bloomId' => 'the-mesh',
            'troop' => 'Spanners',
            'hp' => 10,
            'skills' => ['crafts', 'electronics', 'inconspicuous'],
            'craftType' => null,
            'activeGermIds' => ['beastial-hyphae'],
            'hand' => [
                ['value' => 2, 'suit' => 'diamonds'],
                ['value' => 8, 'suit' => 'clubs'],
                ['value' => 1, 'suit' => 'spades'],
            ],
            'resource' => 'Theme Park Attendant',
            'background' => 'Carnival life.',
            'relationships' => [['name' => 'Bio', 'text' => 'Thinks he is your mentor.']],
            'goals' => [['title' => 'Verdatech', 'text' => 'Find the secrets.']],
            'importNotes' => ['Last year: Network (Any)'],
        ], $overrides);
    }

    private function makeConvention(array $docOverrides = [], array $attributes = []): Character
    {
        $doc = $this->doc($docOverrides);

        return Character::create(array_merge([
            'kind' => 'convention',
            'name' => $doc['nickname'],
            'sheet_code' => $doc['sheetCode'],
            'user_id' => null,
            'created_by' => F::CON_ST,
            'org_unit_id' => F::CON_GAME,
            'character_json' => $doc,
            'current_json' => null,
        ], $attributes));
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_venue_and_national_storytellers_can_list(): void
    {
        $this->makeConvention();

        $this->getJsonAs(F::CON_ST, self::LIST)->assertOk()->assertJsonCount(1);
        $this->getJsonAs(self::NATIONAL_ST, self::LIST)->assertOk()->assertJsonCount(1);
    }

    public function test_other_storytellers_and_players_are_refused(): void
    {
        $c = $this->makeConvention();

        foreach ([F::ST, F::CHAPTER_ST, F::PLAYER, F::BYSTANDER] as $user) {
            $this->getJsonAs($user, self::LIST)->assertStatus(403);
            $this->getJsonAs($user, "/v1/character/{$c->id}/convention")->assertStatus(403);
            $this->asUser($user)->putJson("/v1/character/{$c->id}/convention", [
                'updated_at' => $c->updated_at->toJSON(),
                'character_json' => $this->doc(),
            ])->assertStatus(403);
            $this->asUser($user)->postJson(self::IMPORT, ['characters' => [$this->doc()]])->assertStatus(403);
        }
    }

    public function test_non_convention_venues_are_refused(): void
    {
        $uri = '/v1/org-unit/'.F::GAME.'/convention-characters';

        $this->getJsonAs(F::ST, $uri)->assertStatus(400);
        $this->asUser(F::ST)->postJson($uri, ['character_json' => $this->doc()])->assertStatus(400);
        $this->asUser(F::ST)->postJson($uri.'/import', ['characters' => [$this->doc()]])->assertStatus(400);
    }

    public function test_convention_routes_refuse_standard_characters(): void
    {
        $c = $this->makeCharacter();

        $this->getJsonAs(F::ST, "/v1/character/{$c->id}/convention")->assertStatus(400);
        $this->asUser(F::ST)->deleteJson("/v1/character/{$c->id}/convention")->assertStatus(400);
        $this->assertNotNull($c->fresh());
    }

    // ── List / show ──────────────────────────────────────────────────────────

    public function test_list_is_a_summary_sorted_by_sheet_code_and_full_on_request(): void
    {
        $this->makeConvention(['sheetCode' => 'TH 2', 'nickname' => 'Scout']);
        $this->makeConvention(['sheetCode' => 'DD 1', 'nickname' => 'CB']);

        $rows = $this->getJsonAs(F::CON_ST, self::LIST)->assertOk()->json();

        $this->assertSame(['DD 1', 'TH 2'], array_column($rows, 'sheet_code'));
        $this->assertFalse($rows[0]['full']);
        $this->assertArrayNotHasKey('background', $rows[0]['character_json']);
        $this->assertArrayNotHasKey('relationships', $rows[0]['character_json']);
        $this->assertSame('treehugger', $rows[0]['character_json']['cladeId']);
        $this->assertCount(3, $rows[0]['character_json']['hand']);

        $full = $this->getJsonAs(F::CON_ST, self::LIST.'?full=1')->assertOk()->json();
        $this->assertTrue($full[0]['full']);
        $this->assertSame('Carnival life.', $full[0]['character_json']['background']);
    }

    public function test_show_returns_the_whole_document(): void
    {
        $c = $this->makeConvention();

        $res = $this->getJsonAs(F::CON_ST, "/v1/character/{$c->id}/convention")->assertOk();

        $res->assertJsonPath('kind', 'convention');
        $res->assertJsonPath('sheet_code', 'THM 1');
        $res->assertJsonPath('character_json.goals.0.title', 'Verdatech');
    }

    // ── Create ───────────────────────────────────────────────────────────────

    public function test_create_stores_an_unowned_convention_row(): void
    {
        $res = $this->asUser(F::CON_ST)->postJson(self::LIST, ['character_json' => $this->doc()])->assertStatus(201);

        $c = Character::findOrFail($res->json('id'));
        $this->assertSame('convention', $c->kind);
        $this->assertNull($c->user_id);
        $this->assertSame(F::CON_ST, $c->created_by);
        $this->assertSame('THM 1', $c->sheet_code);
        $this->assertSame('Spin', $c->name);
        $this->assertNull($c->current_json);
    }

    public function test_create_names_by_sheet_code_without_a_nickname(): void
    {
        $res = $this->asUser(F::CON_ST)
            ->postJson(self::LIST, ['character_json' => $this->doc(['nickname' => ''])])
            ->assertStatus(201);

        $this->assertSame('THM 1', $res->json('name'));
    }

    public function test_create_refuses_a_duplicate_sheet_code(): void
    {
        $this->makeConvention();

        $this->asUser(F::CON_ST)->postJson(self::LIST, ['character_json' => $this->doc()])->assertStatus(409);
    }

    public function test_malformed_documents_are_400_with_field_errors(): void
    {
        $bad = $this->doc([
            'format' => 'v2',
            'hand' => [['value' => 11, 'suit' => 'cups']],
            'skills' => 'crafts',
        ]);
        unset($bad['sheetCode']);

        $res = $this->asUser(F::CON_ST)->postJson(self::LIST, ['character_json' => $bad])->assertStatus(400);

        $message = $res->json('message');
        foreach (['format', 'sheet code', 'hand.0.value', 'hand.0.suit', 'skills'] as $field) {
            $this->assertStringContainsStringIgnoringCase($field, $message);
        }

        $this->asUser(F::CON_ST)->postJson(self::LIST, [])->assertStatus(400);
    }

    // ── Import ───────────────────────────────────────────────────────────────

    public function test_import_creates_and_skips_existing_by_default(): void
    {
        $this->makeConvention(['background' => 'ST edited this']);

        $res = $this->asUser(F::CON_ST)->postJson(self::IMPORT, [
            'characters' => [
                $this->doc(['background' => 'From the sheet']),
                $this->doc(['sheetCode' => 'THM 2', 'nickname' => 'Cable']),
            ],
        ])->assertOk();

        $this->assertSame(['THM 2'], $res->json('created'));
        $this->assertSame(['THM 1'], $res->json('skipped'));
        $this->assertSame([], $res->json('updated'));

        $kept = Character::where('sheet_code', 'THM 1')->firstOrFail();
        $this->assertSame('ST edited this', $kept->character_json['background']);
        $this->assertSame(2, Character::convention()->count());
    }

    public function test_import_overwrites_when_asked(): void
    {
        $this->makeConvention(['background' => 'ST edited this']);

        $res = $this->asUser(F::CON_ST)->postJson(self::IMPORT, [
            'characters' => [$this->doc(['background' => 'From the sheet', 'nickname' => 'Spinner'])],
            'overwrite' => true,
        ])->assertOk();

        $this->assertSame(['THM 1'], $res->json('updated'));
        $row = Character::where('sheet_code', 'THM 1')->firstOrFail();
        $this->assertSame('From the sheet', $row->character_json['background']);
        $this->assertSame('Spinner', $row->name);
    }

    public function test_one_bad_row_rejects_the_whole_batch(): void
    {
        $this->asUser(F::CON_ST)->postJson(self::IMPORT, [
            'characters' => [
                $this->doc(['sheetCode' => 'OK 1']),
                $this->doc(['sheetCode' => 'BAD 1', 'hp' => 'lots']),
            ],
        ])->assertStatus(400)->assertJsonPath('status', 400);

        $this->assertSame(0, Character::convention()->count());
    }

    public function test_import_refuses_duplicate_codes_within_the_batch(): void
    {
        $this->asUser(F::CON_ST)->postJson(self::IMPORT, [
            'characters' => [$this->doc(), $this->doc(['nickname' => 'Other'])],
        ])->assertStatus(400);

        $this->assertSame(0, Character::convention()->count());
    }

    // ── Update / delete ──────────────────────────────────────────────────────

    public function test_update_replaces_the_document(): void
    {
        $c = $this->makeConvention();

        $res = $this->asUser(F::CON_ST)->putJson("/v1/character/{$c->id}/convention", [
            'updated_at' => $c->updated_at->toJSON(),
            'character_json' => $this->doc(['activeGermIds' => ['hyphae-wedging'], 'nickname' => 'Spun']),
        ])->assertOk();

        $res->assertJsonPath('character_json.activeGermIds', ['hyphae-wedging']);
        $res->assertJsonPath('name', 'Spun');
        $this->assertNotSame($c->updated_at->toJSON(), $res->json('updated_at'));
    }

    public function test_stale_updated_at_is_409(): void
    {
        $c = $this->makeConvention();
        $loaded = $c->updated_at->toJSON();

        $this->asUser(F::CON_ST)->putJson("/v1/character/{$c->id}/convention", [
            'updated_at' => $loaded,
            'character_json' => $this->doc(['troop' => 'First']),
        ])->assertOk();

        // Same second, same token: still refused because the first save moved it.
        $this->asUser(self::NATIONAL_ST)->putJson("/v1/character/{$c->id}/convention", [
            'updated_at' => $loaded,
            'character_json' => $this->doc(['troop' => 'Second']),
        ])->assertStatus(409);

        $this->assertSame('First', $c->fresh()->character_json['troop']);
    }

    public function test_update_refuses_a_sheet_code_already_taken(): void
    {
        $this->makeConvention(['sheetCode' => 'THM 2', 'nickname' => 'Cable']);
        $c = $this->makeConvention();

        $this->asUser(F::CON_ST)->putJson("/v1/character/{$c->id}/convention", [
            'updated_at' => $c->updated_at->toJSON(),
            'character_json' => $this->doc(['sheetCode' => 'THM 2']),
        ])->assertStatus(409);
    }

    public function test_delete_removes_the_row(): void
    {
        $c = $this->makeConvention();

        $this->asUser(F::CON_ST)->deleteJson("/v1/character/{$c->id}/convention")->assertOk();

        $this->assertNull($c->fresh());
    }

    // ── Existing paths stay closed ───────────────────────────────────────────

    public function test_players_never_see_or_touch_convention_rows(): void
    {
        $c = $this->makeConvention();

        $this->getJsonAs(F::PLAYER, '/v1/character')->assertOk()->assertJsonCount(0);
        $this->getJsonAs(F::PLAYER, "/v1/character/{$c->id}")->assertStatus(403);
        $this->asUser(F::PLAYER)->putJson("/v1/character/{$c->id}", ['name' => 'Mine'])->assertStatus(403);
        $this->asUser(F::PLAYER)->putJson("/v1/character/{$c->id}/retire")->assertStatus(403);
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$c->id}/submission", [
            'type' => 'adjustment',
            'changes_json' => [['kind' => 'adjust.germ-assignment', 'payload' => []]],
        ])->assertStatus(403);
    }

    public function test_storytellers_cannot_use_player_routes_on_convention_rows(): void
    {
        $c = $this->makeConvention();

        $this->getJsonAs(F::CON_ST, "/v1/character/{$c->id}")->assertStatus(400);
        $this->asUser(F::CON_ST)->putJson("/v1/character/{$c->id}/retire")->assertStatus(400);
        $this->asUser(F::CON_ST)->postJson("/v1/character/{$c->id}/anchor", [
            'anchor' => ['type' => 'group', 'name' => 'X'],
        ])->assertStatus(400);
    }

    public function test_roster_and_demographics_skip_convention_rows(): void
    {
        $this->makeConvention();

        $this->getJsonAs(F::CON_ST, '/v1/org-unit/'.F::CON_GAME.'/characters')->assertOk()->assertJsonCount(0);
        $this->getJsonAs(F::CON_ST, '/v1/org-unit/'.F::CON_GAME.'/demographics')
            ->assertOk()
            ->assertJsonCount(0, 'characters');
    }

    public function test_players_cannot_file_characters_into_a_convention_venue(): void
    {
        $envelope = [
            'formatVersion' => 2,
            'identity' => ['characterName' => 'Sneaky'],
            'starting' => ['cladeId' => 'dumpster'],
            'changes' => [],
            'current' => $this->sheet(),
        ];

        $this->asUser(F::PLAYER)->postJson('/v1/character', [
            'org_unit_id' => F::CON_GAME,
            'character_json' => $envelope,
        ])->assertStatus(400);

        $mine = $this->makeCharacter();
        $this->asUser(F::PLAYER)->putJson("/v1/character/{$mine->id}", ['org_unit_id' => F::CON_GAME])
            ->assertStatus(400);
        $this->assertSame(F::GAME, $mine->fresh()->org_unit_id);
    }

    public function test_character_resource_reports_kind(): void
    {
        $this->makeCharacter();

        $this->getJsonAs(F::PLAYER, '/v1/character')->assertOk()->assertJsonPath('0.kind', 'standard');
    }
}
