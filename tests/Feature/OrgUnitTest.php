<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\OrgUnit;
use App\Models\User;
use Database\Seeders\TestFixturesSeeder as F;
use Tests\FeatureTestCase;

/**
 * Ports the intent of test/org-unit.js against the beta fixtures.
 *
 * Beta hierarchy: 1 Nation -> 2 Chapter (WPI-PA-001), 3 Chapter -> 4/5 Venues.
 * nc = user 2 (national Club Manager, office 1). admin = persona 104.
 * plain member = user 5 (no office). expired = 100, suspended = 101.
 */
class OrgUnitTest extends FeatureTestCase
{
    // --- static reference routes (no token) -------------------------------

    public function test_types_is_public(): void
    {
        $this->getJson('/v1/org-unit/types')
            ->assertOk()
            ->assertExactJson(['Nation', 'Region', 'Chapter', 'Venue']);
    }

    public function test_venues_and_organizations_are_public(): void
    {
        $venues = $this->getJson('/v1/org-unit/venues')->assertOk()->json();

        // The route serves the catalogue verbatim, so assert that rather than a
        // fixed length — the catalogue is edited whenever a venue is retired.
        $this->assertSame(array_column(config('wpi.venues'), 'id'), array_column($venues, 'id'));
        $this->assertContains('Sporebound', array_column($venues, 'id'));

        $this->getJson('/v1/org-unit/organizations')->assertOk()->assertJsonPath('0.id', 'WPI');
    }

    // --- GET show ---------------------------------------------------------

    public function test_show_requires_token(): void
    {
        $this->getJson('/v1/org-unit/3')->assertStatus(403);
    }

    public function test_show_unknown_code_is_404(): void
    {
        $this->getJsonAs(5, '/v1/org-unit/fd-434221')->assertStatus(404);
    }

    public function test_show_by_id_and_me(): void
    {
        $this->getJsonAs(5, '/v1/org-unit/1')->assertOk()->assertJsonPath('id', 1);
        $this->getJsonAs(F::NC, '/v1/org-unit/me')->assertOk()->assertJsonPath('id', 2);
    }

    /**
     * DIVERGENCE: the code-lookup regex ^[a-z]{2}[-\d]*$ only matches 2-letter
     * codes (legacy NY/NE); the beta codes are WPI-* so the controller treats
     * them as an invalid id (400). Kept faithful to the source; flagged.
     */
    public function test_multiletter_code_is_rejected_by_regex(): void
    {
        $this->getJsonAs(5, '/v1/org-unit/WPI-PA-001')->assertStatus(400);
    }

    public function test_me_without_org_unit_is_404(): void
    {
        $this->getJsonAs(F::CHAPTERLESS, '/v1/org-unit/me')->assertStatus(404);
    }

    public function test_show_includes_unit_users_offices_and_chain(): void
    {
        $res = $this->getJsonAs(5, '/v1/org-unit/3')->assertOk();
        $res->assertJsonPath('id', 3);
        $res->assertJsonStructure(['id', 'name', 'users', 'offices', 'parents', 'children']);
        // org 3 path "1.3": one ancestor (Nation), two descendant venues.
        $this->assertCount(1, $res->json('parents'));
        $this->assertCount(2, $res->json('children'));
    }

    public function test_show_excludes_sections_when_flag_zero(): void
    {
        foreach (['offices', 'users', 'parents', 'children'] as $section) {
            $res = $this->getJsonAs(5, "/v1/org-unit/3?{$section}=0")->assertOk();
            $this->assertArrayNotHasKey($section, $res->json());
        }
    }

    public function test_children_depth_one_has_no_grandchildren(): void
    {
        $res = $this->getJsonAs(5, '/v1/org-unit/1?children=1')->assertOk();
        foreach ($res->json('children') as $child) {
            $this->assertArrayNotHasKey('children', $child);
        }
    }

    public function test_children_depth_two_keeps_one_level(): void
    {
        $res = $this->getJsonAs(5, '/v1/org-unit/1?children=2')->assertOk();
        $hadNested = false;
        foreach ($res->json('children') as $child) {
            $this->assertArrayHasKey('children', $child);
            foreach ($child['children'] as $grand) {
                $hadNested = true;
                $this->assertArrayNotHasKey('children', $grand);
            }
        }
        $this->assertTrue($hadNested, 'expected at least one nested venue under a chapter');
    }

    public function test_parents_depth_trims(): void
    {
        // org 4 path "1.3.4": ancestors [1,3].
        $this->assertCount(1, $this->getJsonAs(5, '/v1/org-unit/4?parents=1')->json('parents'));
        $this->assertCount(2, $this->getJsonAs(5, '/v1/org-unit/4?parents=2')->json('parents'));
    }

    public function test_venue_filter_narrows_children_to_one_venue_type(): void
    {
        $this->markOnlyVenue4Sporebound();

        // org 3 path "1.3": venues 4 and 5 hang directly under it.
        $res = $this->getJsonAs(5, '/v1/org-unit/3?venue=Sporebound')->assertOk();
        $this->assertSame([4], array_column($res->json('children'), 'id'));

        // Unfiltered, both are still there.
        $this->assertCount(2, $this->getJsonAs(5, '/v1/org-unit/3')->json('children'));
    }

    public function test_venue_filter_is_case_insensitive_and_keeps_the_branch(): void
    {
        $this->markOnlyVenue4Sporebound();

        // From the Nation, chapter 3 must survive as the branch holding venue 4.
        $res = $this->getJsonAs(5, '/v1/org-unit/1?venue=sporebound')->assertOk();

        $this->assertSame([4], $this->venueIdsIn($res->json('children')));
    }

    /** Leaves exactly one Sporebound venue (org 4) so assertions are exact. */
    private function markOnlyVenue4Sporebound(): void
    {
        OrgUnit::where('type', 'Venue')->update(['venueType' => 'VtM']);
        OrgUnit::whereKey(4)->update(['venueType' => 'Sporebound']);
    }

    /**
     * @return int[]
     */
    private function venueIdsIn(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'Venue') {
                $ids[] = $node['id'];
            }
            $ids = array_merge($ids, $this->venueIdsIn($node['children'] ?? []));
        }

        return $ids;
    }

    // --- PUT update -------------------------------------------------------

    public function test_update_requires_token(): void
    {
        $this->putJson('/v1/org-unit/3', ['name' => 'Test'])->assertStatus(403);
    }

    public function test_update_unknown_is_404(): void
    {
        $this->asUser(F::NC)->putJson('/v1/org-unit/99', ['name' => 'Test'])->assertStatus(404);
    }

    public function test_update_blocked_for_expired_and_suspended(): void
    {
        $this->asUser(F::EXPIRED)->putJson('/v1/org-unit/3', ['name' => 'Test'])->assertStatus(403);
        $this->asUser(F::SUSPENDED)->putJson('/v1/org-unit/3', ['name' => 'Test'])->assertStatus(403);
    }

    public function test_update_without_data_is_400(): void
    {
        $this->asUser(F::NC)->putJson('/v1/org-unit/3', [])->assertStatus(400);
    }

    public function test_update_without_permission_is_403(): void
    {
        $this->asUser(5)->putJson('/v1/org-unit/3', ['name' => 'Test'])->assertStatus(403);
    }

    public function test_update_invalid_website_is_400(): void
    {
        $this->asUser(F::NC)->putJson('/v1/org-unit/3', ['website' => 'Blah'])->assertStatus(400);
    }

    public function test_update_succeeds_with_permission(): void
    {
        $this->asUser(F::NC)->putJson('/v1/org-unit/3', ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed');
        $this->assertSame('Renamed', OrgUnit::find(3)->name);
    }

    // --- POST create ------------------------------------------------------

    private function chapterData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Chapter',
            'code' => 'XX-000',
            'location' => 'Narnia',
            'defDoc' => 'Test chapter, please ignore!',
            'website' => 'http://www.example.com',
            'type' => 'Chapter',
            'parentID' => 1,
        ], $overrides);
    }

    public function test_create_requires_token(): void
    {
        $this->postJson('/v1/org-unit', $this->chapterData())->assertStatus(403);
    }

    public function test_create_without_data_is_400(): void
    {
        $this->asUser(F::ADMIN)->postJson('/v1/org-unit', [])->assertStatus(400);
    }

    public function test_create_blocked_for_expired_and_suspended(): void
    {
        $this->asUser(F::EXPIRED)->postJson('/v1/org-unit', $this->chapterData())->assertStatus(403);
        $this->asUser(F::SUSPENDED)->postJson('/v1/org-unit', $this->chapterData())->assertStatus(403);
    }

    public function test_create_without_permission_is_403(): void
    {
        $this->asUser(5)->postJson('/v1/org-unit', $this->chapterData())->assertStatus(403);
    }

    public function test_create_validation_failures(): void
    {
        $this->asUser(F::NC)->postJson('/v1/org-unit', $this->chapterData(['parentID' => null]))->assertStatus(400);
        $this->asUser(F::NC)->postJson('/v1/org-unit', $this->chapterData(['parentID' => 99]))->assertStatus(400);
        $this->asUser(F::NC)->postJson('/v1/org-unit', $this->chapterData(['type' => 'Nation']))->assertStatus(400);
        $this->asUser(F::NC)->postJson('/v1/org-unit', $this->chapterData(['type' => 'Wrong']))->assertStatus(400);
    }

    public function test_create_succeeds_with_permission(): void
    {
        $res = $this->asUser(F::NC)->postJson('/v1/org-unit', $this->chapterData())->assertOk();
        $res->assertJsonPath('name', 'Test Chapter')
            ->assertJsonPath('code', 'XX-000')
            ->assertJsonPath('type', 'Chapter');
        $this->assertNotEmpty($res->json('offices'), 'manager office should be created');

        $id = $res->json('id');
        $this->assertDatabaseHas('org_units', ['id' => $id, 'name' => 'Test Chapter']);
        $this->assertDatabaseHas('offices', ['parentOrgID' => $id]);
    }

    // --- DELETE -----------------------------------------------------------

    public function test_delete_requires_token(): void
    {
        $this->deleteJson('/v1/org-unit/4')->assertStatus(403);
    }

    public function test_delete_without_permission_is_403(): void
    {
        $this->asUser(5)->deleteJson('/v1/org-unit/4')->assertStatus(403);
    }

    public function test_delete_blocked_for_expired_and_suspended(): void
    {
        $this->asUser(F::EXPIRED)->deleteJson('/v1/org-unit/4')->assertStatus(403);
        $this->asUser(F::SUSPENDED)->deleteJson('/v1/org-unit/4')->assertStatus(403);
    }

    public function test_delete_root_is_rejected(): void
    {
        $this->asUser(F::ADMIN)->deleteJson('/v1/org-unit/1')->assertStatus(500);
    }

    public function test_delete_unit_with_children_is_rejected(): void
    {
        // org 3 (Chapter) has venues 4 and 5.
        $this->asUser(F::NC)->deleteJson('/v1/org-unit/3')->assertStatus(500);
    }

    public function test_delete_leaf_removes_offices(): void
    {
        // Venue 4 is a leaf; office 8 governs it.
        $this->assertDatabaseHas('offices', ['parentOrgID' => 4]);
        $this->asUser(F::NC)->deleteJson('/v1/org-unit/4')->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseMissing('org_units', ['id' => 4]);
        $this->assertDatabaseMissing('offices', ['parentOrgID' => 4]);
    }

    public function test_delete_reparents_users(): void
    {
        // Put a member directly on venue 4, then delete it: they move to parent (3).
        User::where('id', 5)->update(['orgUnit' => 4]);
        $this->asUser(F::NC)->deleteJson('/v1/org-unit/4')->assertOk();
        $this->assertSame(3, User::find(5)->orgUnit);
    }

    public function test_list_requires_token(): void
    {
        $this->getJson('/v1/org-unit?name=children')->assertStatus(403);
    }

    public function test_list_filters(): void
    {
        $this->getJsonAs(5, '/v1/org-unit')->assertOk()->assertJsonIsArray();
        $this->getJsonAs(5, '/v1/org-unit?type=test')->assertStatus(400);
        $this->getJsonAs(5, '/v1/org-unit?code=XX&venue=CL')->assertStatus(400);
        $this->getJsonAs(5, '/v1/org-unit?venue=CL&type=region')->assertStatus(400);
        $this->assertEmpty($this->getJsonAs(5, '/v1/org-unit?name=zzzznomatch')->json());
        $this->assertNotEmpty($this->getJsonAs(5, '/v1/org-unit?type=chapter')->json());
    }
}
