<?php

namespace Tests\Feature;

use App\Models\Office;
use Database\Seeders\TestFixturesSeeder as F;
use Tests\FeatureTestCase;

/**
 * Ports the intent of test/office.js against the beta fixtures.
 *
 * Beta offices: 1 national (user 2 = nc, all roles), 2/3/4 national assistants,
 * 5 chapter-2 owner (user 3), 6 chapter-3 owner (vacant). admin = persona 104.
 */
class OfficeTest extends FeatureTestCase
{
    // --- GET /office/{id} -------------------------------------------------

    public function test_show_requires_token(): void
    {
        $this->getJson('/v1/office/1')->assertStatus(403);
    }

    public function test_show_unknown_is_404(): void
    {
        $this->getJsonAs(5, '/v1/office/9999')->assertStatus(404);
    }

    public function test_show_returns_office_with_chain(): void
    {
        $res = $this->getJsonAs(5, '/v1/office/1')->assertOk();
        $res->assertJsonPath('id', 1)
            ->assertJsonStructure(['id', 'name', 'type', 'roles', 'parentOffice', 'orgUnit', 'user', 'parents', 'children']);
        // Office 1's path-derived fields are dropped from the top level.
        $this->assertArrayNotHasKey('parentPath', $res->json());
        $this->assertArrayNotHasKey('userID', $res->json());
        // Holder is user 2; governs org 1.
        $this->assertSame(2, $res->json('user.id'));
        $this->assertSame(1, $res->json('orgUnit.id'));
    }

    // --- GET /office/me ---------------------------------------------------

    public function test_me_lists_held_offices(): void
    {
        // user 2 holds offices 1 and 4.
        $this->getJsonAs(F::NC, '/v1/office/me')->assertOk()->assertJsonCount(2);
        // user 5 holds none.
        $this->getJsonAs(5, '/v1/office/me')->assertOk()->assertJsonCount(0);
    }

    // --- GET /office/roles ------------------------------------------------

    public function test_roles_is_public(): void
    {
        $this->getJson('/v1/office/roles')->assertOk()->assertJsonCount(25);
    }

    // --- POST /office -----------------------------------------------------

    public function test_create_requires_admin(): void
    {
        $payload = ['name' => 'New National', 'roles' => ['user_read_private']];
        // nc lacks the admin role.
        $this->asUser(F::NC)->postJson('/v1/office', $payload)->assertStatus(403);
        // admin persona succeeds.
        $res = $this->asUser(F::ADMIN)->postJson('/v1/office', $payload)->assertOk();
        $id = $res->json('id');
        $this->assertSame((string) $id, Office::find($id)->parentPath); // parentPath == own id
        $this->assertSame('Primary', Office::find($id)->type);
    }

    public function test_create_validation(): void
    {
        $this->asUser(F::ADMIN)->postJson('/v1/office', ['roles' => ['x']])->assertStatus(400); // no name
        $this->asUser(F::ADMIN)->postJson('/v1/office', ['name' => 'X'])->assertStatus(400);     // no roles
    }

    // --- PUT /office/{id}/assign/{user} -----------------------------------

    public function test_assign_fills_and_vacates(): void
    {
        // nc (office 1, national) is above chapter-3 office 6 (currently vacant).
        $this->asUser(F::NC)->putJson('/v1/office/6/assign/35')->assertOk()->assertJson(['success' => true]);
        $this->assertSame(35, Office::find(6)->userID);

        // vacate office 5 (held by user 3)
        $this->asUser(F::NC)->putJson('/v1/office/5/assign/0')->assertOk();
        $this->assertNull(Office::find(5)->userID);
    }

    public function test_assign_sanity_checks(): void
    {
        // office 1 already held by user 2
        $this->asUser(F::NC)->putJson('/v1/office/1/assign/2')->assertStatus(500);
        // NOTE: "Office already vacant" only fires when userID === 0; vacant
        // offices store NULL, so re-vacating just succeeds (matches Node).
        $this->asUser(F::NC)->putJson('/v1/office/6/assign/0')->assertOk();
    }

    public function test_assign_not_found_and_auth(): void
    {
        $this->putJson('/v1/office/6/assign/35')->assertStatus(403);          // no token
        $this->asUser(F::EXPIRED)->putJson('/v1/office/6/assign/35')->assertStatus(403); // member gate
        $this->asUser(F::NC)->putJson('/v1/office/9999/assign/35')->assertStatus(404);   // office
        $this->asUser(F::NC)->putJson('/v1/office/6/assign/9999')->assertStatus(404);    // user
        $this->asUser(5)->putJson('/v1/office/6/assign/35')->assertStatus(403);          // no offices
    }

    // --- PUT /office/{id} -------------------------------------------------

    public function test_update_name_and_roles(): void
    {
        $this->asUser(F::NC)->putJson('/v1/office/6', ['name' => 'Renamed Office'])
            ->assertOk()->assertJsonPath('name', 'Renamed Office');
        $this->assertSame('Renamed Office', Office::find(6)->name);

        $this->asUser(F::NC)->putJson('/v1/office/6', ['roles' => ['user_update']])
            ->assertOk()->assertJsonPath('roles', ['user_update']);
    }

    public function test_update_failures(): void
    {
        $this->asUser(F::NC)->putJson('/v1/office/6', [])->assertStatus(400);          // no data
        $this->asUser(F::NC)->putJson('/v1/office/6', ['email' => 'bad'])->assertStatus(400);
        $this->asUser(F::NC)->putJson('/v1/office/9999', ['name' => 'X'])->assertStatus(404);
        $this->asUser(5)->putJson('/v1/office/6', ['name' => 'X'])->assertStatus(403); // no offices
    }

    // --- assistants -------------------------------------------------------

    public function test_create_assistant_under_own_office(): void
    {
        $res = $this->asUser(F::NC)->postJson('/v1/office/1/assistant', [
            'name' => 'Helper', 'roles' => ['user_update'],
        ])->assertOk();
        $id = $res->json('id');
        $office = Office::find($id);
        $this->assertSame('Assistant', $office->type);
        $this->assertSame(1, $office->parentOfficeID);
        $this->assertSame("1.{$id}", $office->parentPath);
    }

    public function test_create_assistant_role_not_in_parent_is_400(): void
    {
        $this->asUser(F::NC)->postJson('/v1/office/1/assistant', [
            'name' => 'Helper', 'roles' => ['not_a_real_role'],
        ])->assertStatus(400);
    }

    public function test_create_assistant_without_offices_is_403(): void
    {
        $this->asUser(5)->postJson('/v1/office/1/assistant', ['name' => 'X', 'roles' => []])->assertStatus(403);
    }

    public function test_delete_assistant(): void
    {
        // office 2 is a national assistant.
        $this->asUser(F::NC)->deleteJson('/v1/office/2/assistant')->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseMissing('offices', ['id' => 2]);
    }

    public function test_delete_assistant_on_primary_is_400(): void
    {
        $this->asUser(F::NC)->deleteJson('/v1/office/1/assistant')->assertStatus(400);
    }

    // --- verify -----------------------------------------------------------

    public function test_verify_orgunit_success_and_failure(): void
    {
        $this->asUser(F::NC)->getJson('/v1/office/verify/orgunit/2?roles=org_update')
            ->assertOk()->assertJson(['success' => true]);

        // missing roles param
        $this->asUser(F::NC)->getJson('/v1/office/verify/orgunit/2')->assertStatus(400);

        // user without offices => collapsed to 403
        $this->asUser(5)->getJson('/v1/office/verify/orgunit/2?roles=org_update')->assertStatus(403);
    }

    public function test_verify_user_and_office(): void
    {
        $this->asUser(F::NC)->getJson('/v1/office/verify/user/35?roles=user_update')
            ->assertOk()->assertJson(['success' => true]);
        $this->asUser(F::NC)->getJson('/v1/office/verify/office/6?roles=office_update')
            ->assertOk()->assertJson(['success' => true]);
    }
}
