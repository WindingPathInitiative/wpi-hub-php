<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\TestFixturesSeeder as F;
use Tests\FeatureTestCase;

/**
 * Ports the intent of test/user.js against the beta fixtures.
 *
 * nc = user 2 (national, all user_* roles). plain = user 5 (org 2, no office).
 * expired = 100, suspended = 101, chapterless = 102 (Full, no org).
 */
class UserTest extends FeatureTestCase
{
    // --- GET list ---------------------------------------------------------

    public function test_list_requires_token(): void
    {
        $this->getJson('/v1/user')->assertStatus(403);
    }

    public function test_list_unknown_org_unit_is_404(): void
    {
        $this->getJsonAs(5, '/v1/user?orgUnit=99')->assertStatus(404);
    }

    public function test_list_filters(): void
    {
        $this->getJsonAs(5, '/v1/user')->assertOk()->assertJsonIsArray();
        $this->assertEmpty($this->getJsonAs(5, '/v1/user?name=zzzznomatch')->json());
        $this->assertNotEmpty($this->getJsonAs(5, '/v1/user?name=Jeremy')->json());
        $email = User::find(2)->email;
        $this->assertNotEmpty($this->getJsonAs(5, '/v1/user?email='.urlencode($email))->json());
        $this->assertNotEmpty($this->getJsonAs(5, '/v1/user?membershipNumber=WPI20180002')->json());
        $this->assertNotEmpty($this->getJsonAs(5, '/v1/user?orgUnit=2')->json());
        $this->assertEmpty($this->getJsonAs(5, '/v1/user?orgUnit=4')->json());
        $this->assertNotEmpty($this->getJsonAs(5, '/v1/user?type=full')->json());
    }

    public function test_list_expired_filter(): void
    {
        $expiredIds = collect($this->getJsonAs(5, '/v1/user?expired=true')->json())->pluck('id');
        $this->assertTrue($expiredIds->contains(F::EXPIRED));

        $activeIds = collect($this->getJsonAs(5, '/v1/user?expired=false')->json())->pluck('id');
        $this->assertFalse($activeIds->contains(F::EXPIRED));
    }

    public function test_list_returns_public_users_without_pii(): void
    {
        $first = $this->getJsonAs(5, '/v1/user?name=Jeremy')->json()[0];
        $this->assertArrayHasKey('membershipNumber', $first);
        $this->assertArrayNotHasKey('email', $first);
        $this->assertArrayNotHasKey('firstName', $first);
    }

    // --- GET show ---------------------------------------------------------

    public function test_show_requires_token(): void
    {
        $this->getJson('/v1/user/1')->assertStatus(403);
    }

    public function test_show_unknown_id_is_404(): void
    {
        $this->getJsonAs(5, '/v1/user/999999')->assertStatus(404);
    }

    /**
     * DIVERGENCE: the membership-number regex ^[a-z]{2}\d{10}$ expects a
     * 2-letter prefix + 10 digits; beta numbers are WPI######## (3 letters),
     * so the controller treats them as an invalid id (400). Flagged.
     */
    public function test_beta_membership_number_is_rejected_by_regex(): void
    {
        $this->getJsonAs(5, '/v1/user/WPI20180002')->assertStatus(400);
    }

    public function test_show_self_includes_private(): void
    {
        $this->getJsonAs(5, '/v1/user/me')->assertOk()
            ->assertJsonPath('id', 5)
            ->assertJsonStructure(['id', 'firstName', 'lastName', 'fullName', 'email', 'orgUnit']);
    }

    public function test_show_other_without_permission_hides_private(): void
    {
        $res = $this->getJsonAs(5, '/v1/user/1?private=1')->assertOk();
        // model serialization drops PII when not showPrivate (and never exposes it without permission)
        $this->assertArrayNotHasKey('email', $res->json());
    }

    public function test_show_other_with_permission_includes_private(): void
    {
        $this->getJsonAs(F::NC, '/v1/user/1?private=1')->assertOk()
            ->assertJsonStructure(['email', 'firstName']);
    }

    public function test_show_other_blocked_when_actor_expired(): void
    {
        $this->getJsonAs(F::EXPIRED, '/v1/user/1?private=1')->assertStatus(403);
    }

    public function test_show_offices_and_children(): void
    {
        $res = $this->getJsonAs(F::NC, '/v1/user/2?offices=1&children=1')->assertOk();
        $this->assertNotEmpty($res->json('offices'));
        foreach ($res->json('offices') as $office) {
            $this->assertArrayHasKey('children', $office);
        }
    }

    // --- PUT update -------------------------------------------------------

    public function test_update_requires_token(): void
    {
        $this->putJson('/v1/user/9', ['firstName' => 'Test'])->assertStatus(403);
    }

    public function test_update_member_gate(): void
    {
        $this->asUser(F::EXPIRED)->putJson('/v1/user/9', ['firstName' => 'X'])->assertStatus(403);
        $this->asUser(F::SUSPENDED)->putJson('/v1/user/9', ['firstName' => 'X'])->assertStatus(403);
    }

    public function test_update_validation(): void
    {
        $this->asUser(F::NC)->putJson('/v1/user/999999', ['firstName' => 'X'])->assertStatus(404);
        $this->asUser(F::NC)->putJson('/v1/user/3', [])->assertStatus(400);
        $this->asUser(F::NC)->putJson('/v1/user/3', ['email' => 'bad'])->assertStatus(400);
        // unknown-only payload validates to nothing => 400 (port whitelists fields)
        $this->asUser(F::NC)->putJson('/v1/user/3', ['membershipType' => 'Full'])->assertStatus(400);
    }

    public function test_update_without_permission_is_403(): void
    {
        $this->asUser(5)->putJson('/v1/user/3', ['firstName' => 'Nope'])->assertStatus(403);
    }

    public function test_update_self_and_with_permission(): void
    {
        $this->asUser(5)->putJson('/v1/user/me', ['firstName' => 'Selfie'])
            ->assertOk()->assertJsonPath('firstName', 'Selfie');
        $this->assertSame('Selfie', User::find(5)->firstName);

        $this->asUser(F::NC)->putJson('/v1/user/3', ['firstName' => 'ByNc'])
            ->assertOk()->assertJsonPath('firstName', 'ByNc');
    }

    // --- PUT approve ------------------------------------------------------

    public function test_approve_existing_member_is_rejected(): void
    {
        $this->asUser(F::NC)->putJson('/v1/user/3/approve')->assertStatus(500);
    }

    public function test_approve_issues_membership_number(): void
    {
        // create a fresh (unapproved) member, then approve.
        $created = $this->asUser(F::NC)->postJson('/v1/user', [
            'firstName' => 'New', 'lastName' => 'Recruit', 'nickname' => 'NR',
            'email' => 'recruit@example.com', 'orgUnit' => 2,
        ])->assertOk()->json();

        $res = $this->asUser(F::NC)->putJson("/v1/user/{$created['id']}/approve")->assertOk();
        $res->assertJsonPath('membershipType', 'Full');
        $this->assertStringStartsWith('WPI', $res->json('membershipNumber'));
    }

    // --- PUT assign -------------------------------------------------------

    public function test_assign_self_without_chapter(): void
    {
        $this->asUser(F::CHAPTERLESS)->putJson('/v1/user/me/assign/2')->assertOk()->assertJson(['success' => true]);
        $this->assertSame(2, User::find(F::CHAPTERLESS)->orgUnit);
    }

    public function test_assign_self_from_chapter_is_blocked(): void
    {
        // user 5 is in chapter 2; cannot leave a chapter on their own.
        $this->asUser(5)->putJson('/v1/user/me/assign/3')->assertStatus(403);
    }

    public function test_assign_to_venue_is_rejected(): void
    {
        $this->asUser(F::NC)->putJson('/v1/user/102/assign/4')->assertStatus(500);
    }

    public function test_assign_unknown_chapter_is_404(): void
    {
        $this->asUser(F::NC)->putJson('/v1/user/102/assign/999')->assertStatus(404);
    }

    public function test_assign_already_in_chapter_is_rejected(): void
    {
        $this->asUser(F::NC)->putJson('/v1/user/5/assign/2')->assertStatus(500);
    }

    public function test_assign_other_with_permission(): void
    {
        $this->asUser(F::NC)->putJson('/v1/user/3/assign/3')->assertOk();
        $this->assertSame(3, User::find(3)->orgUnit);
    }

    // --- PUT suspend ------------------------------------------------------

    public function test_suspend_and_restore(): void
    {
        $this->asUser(F::NC)->putJson('/v1/user/9/suspend')->assertOk()->assertJsonPath('membershipType', 'Suspended');
        $this->asUser(F::NC)->putJson('/v1/user/'.F::SUSPENDED.'/suspend?restore=1')
            ->assertOk()->assertJsonPath('membershipType', 'Full');
    }

    public function test_suspend_without_permission_is_403(): void
    {
        $this->asUser(5)->putJson('/v1/user/9/suspend')->assertStatus(403);
    }

    // --- POST create ------------------------------------------------------

    public function test_create_validation_and_permission(): void
    {
        $payload = [
            'firstName' => 'A', 'lastName' => 'B', 'nickname' => 'AB',
            'email' => 'newperson@example.com', 'orgUnit' => 2,
        ];
        $this->postJson('/v1/user', $payload)->assertStatus(403);                       // no token
        $this->asUser(F::NC)->postJson('/v1/user', [])->assertStatus(400);              // no data
        $this->asUser(F::NC)->postJson('/v1/user', ['firstName' => 'x'])->assertStatus(400); // no orgUnit
        $this->asUser(F::NC)->postJson('/v1/user', array_merge($payload, ['orgUnit' => 4]))->assertStatus(500); // venue
        $this->asUser(5)->postJson('/v1/user', $payload)->assertStatus(403);            // no permission
        // duplicate email (use an existing beta user's email)
        $existing = User::find(2)->email;
        $this->asUser(F::NC)->postJson('/v1/user', array_merge($payload, ['email' => $existing]))->assertStatus(400);
    }

    public function test_create_succeeds(): void
    {
        $res = $this->asUser(F::NC)->postJson('/v1/user', [
            'firstName' => 'Made', 'lastName' => 'Member', 'nickname' => 'MM',
            'email' => 'made@example.com', 'orgUnit' => 2,
        ])->assertOk();
        $res->assertJsonPath('membershipType', 'None')->assertJsonPath('firstName', 'Made');
        $this->assertDatabaseHas('users', ['email' => 'made@example.com', 'membershipType' => 'None']);
    }

    // --- internal endpoints (shared secret) -------------------------------

    public function test_portal_requires_internal_secret(): void
    {
        $this->postJson('/v1/user/portal', [])->assertStatus(403)
            ->assertJson(['message' => 'Request over insecure port']);
    }

    public function test_portal_requires_payload(): void
    {
        $this->withInternalSecret()->postJson('/v1/user/portal', [])->assertStatus(400)
            ->assertJson(['message' => 'Invalid POST']);
    }

    public function test_portal_upserts_user(): void
    {
        $this->withInternalSecret()->postJson('/v1/user/portal', [
            'id' => 'portal-xyz',
            'changes' => [
                'membership_number' => 'WPI20200500',
                'firstname' => 'Portal', 'lastname' => 'User', 'nickname' => 'PU',
                'email' => 'portal.user@example.com',
                'address' => '1 Main St', 'city' => 'Town', 'state' => 'PA', 'zip' => '19000',
                'membership_expiration' => ['date' => '2025-01-01 00:00:00'],
                'type' => 'Full',
            ],
        ])->assertOk()->assertJsonPath('firstName', 'Portal');

        $this->assertDatabaseHas('users', ['portalID' => 'portal-xyz', 'membershipNumber' => 'WPI20200500']);
    }

    public function test_internal_requires_secret_and_returns_full_record(): void
    {
        $this->getJson('/v1/user/1/internal')->assertStatus(403);
        $this->withInternalSecret()->getJson('/v1/user/100000/internal')->assertStatus(404);

        $res = $this->withInternalSecret()->getJson('/v1/user/1/internal')->assertOk();
        $res->assertJsonStructure(['id', 'email', 'orgUnit', 'offices']);
    }
}
