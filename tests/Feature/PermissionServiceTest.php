<?php

namespace Tests\Feature;

use App\Exceptions\UserException;
use App\Models\Office;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\PresetOfficeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit coverage for the ported PermissionService, exercising every branch with
 * a self-consistent hierarchy. (The legacy test/permission.js scenarios depend
 * on the shared HTTP fixtures and are covered at that level in Phases 4-5.)
 *
 * Org units:  1 Nation "1" · 2 Region "1.2" · 3 Chapter "1.2.3" · 6 Chapter "1.6"
 * Offices:    1 national(nc=u2) "1" · 3 region(rc=u3) "1.3" · 4 chapter(dc=u8) "1.3.4"
 *             7 target "1.3.7" · 10 assistant->3 (u10) "1.3.10" · 20 other(u30) "1.20"
 *             admin office (u1) "1" roles=[admin]
 */
class PermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private const PERM = 'user_read_private';

    private PermissionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedHierarchy();
        $this->svc = app(PermissionService::class);
    }

    private function unit(int $id, string $type, string $path): void
    {
        OrgUnit::forceCreate(['id' => $id, 'name' => "u{$id}", 'type' => $type, 'parentPath' => $path]);
    }

    private function user(int $id, ?int $org): void
    {
        User::forceCreate([
            'id' => $id, 'firstName' => "f{$id}", 'lastName' => 'x',
            'email' => "u{$id}@e.com", 'membershipType' => 'Full',
            'membershipNumber' => 'WPI2018'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'membershipExpiration' => '2999-01-01', 'orgUnit' => $org,
        ]);
    }

    private function office(int $id, int $orgId, string $path, ?int $userId, array $roles, string $type = 'Primary', ?int $parentOfficeId = null): void
    {
        Office::forceCreate([
            'id' => $id, 'name' => "o{$id}", 'type' => $type,
            'parentOrgID' => $orgId, 'parentPath' => $path, 'userID' => $userId,
            'parentOfficeID' => $parentOfficeId, 'roles' => $roles,
        ]);
    }

    private function seedHierarchy(): void
    {
        $this->unit(1, 'Nation', '1');
        $this->unit(2, 'Region', '1.2');
        $this->unit(3, 'Chapter', '1.2.3');
        $this->unit(6, 'Chapter', '1.6');

        $this->user(1, null);  // admin
        $this->user(2, null);  // nc (national officer)
        $this->user(3, 2);     // rc (region officer)
        $this->user(8, 3);     // dc (chapter officer)
        $this->user(5, 3);     // plain member, chapter 3
        $this->user(9, null);  // chapterless member
        $this->user(10, null); // assistant holder
        $this->user(30, 6);    // other-branch officer
        $this->user(50, 6);    // plain member, chapter 6

        // admin office shares path "1" with the national office; only roles differ.
        $this->office(2, 1, '1', 1, ['admin'], 'Primary');          // admin's office (id 2 to avoid clash)
        $this->office(1, 1, '1', 2, [self::PERM, 'org_update'], 'Primary');   // nc national
        $this->office(3, 2, '1.3', 3, [self::PERM], 'Primary');     // rc region
        $this->office(4, 3, '1.3.4', 8, [self::PERM], 'Primary');   // dc chapter
        $this->office(7, 3, '1.3.7', null, [self::PERM], 'Primary'); // target office
        $this->office(10, 3, '1.3.10', 10, [self::PERM], 'Assistant', parentOfficeId: 3);
        $this->office(20, 6, '1.20', 30, [self::PERM], 'Primary');  // separate branch
    }

    // --- has --------------------------------------------------------------

    public function test_has_returns_matching_offices(): void
    {
        $this->assertCount(1, $this->svc->has(self::PERM, 2)); // nc
    }

    public function test_has_throws_when_no_offices(): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('User has no offices');
        $this->svc->has(self::PERM, 5);
    }

    public function test_has_throws_when_no_matching_role(): void
    {
        $this->expectExceptionMessage('No offices with permission');
        $this->svc->has('barnacles', 2);
    }

    public function test_admin_always_passes(): void
    {
        $this->assertCount(1, $this->svc->has('barnacles', 1)); // admin
    }

    // --- hasOverUser ------------------------------------------------------

    public function test_over_user_same_unit(): void
    {
        $this->assertCount(1, $this->svc->hasOverUser(5, self::PERM, 8)); // dc over member in ch3
    }

    public function test_over_user_ancestor_unit(): void
    {
        $this->assertCount(1, $this->svc->hasOverUser(5, self::PERM, 3)); // rc over member in ch3
    }

    public function test_over_user_not_in_chain_throws(): void
    {
        $this->expectExceptionMessage('Officer not found in chain');
        $this->svc->hasOverUser(50, self::PERM, 3); // rc (region 2) over member in ch6
    }

    public function test_over_user_no_unit_requires_national(): void
    {
        $this->assertCount(1, $this->svc->hasOverUser(9, self::PERM, 2)); // nc over chapterless
    }

    public function test_over_user_no_unit_non_national_throws(): void
    {
        $this->expectExceptionMessage('Not national officer');
        $this->svc->hasOverUser(9, self::PERM, 8); // dc over chapterless
    }

    public function test_over_user_missing_user_404(): void
    {
        try {
            $this->svc->hasOverUser(999, self::PERM, 2);
            $this->fail('expected 404');
        } catch (UserException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    // --- hasOverUnit ------------------------------------------------------

    public function test_over_unit_national(): void
    {
        $this->assertCount(1, $this->svc->hasOverUnit(null, self::PERM, 2)); // nc, default national
    }

    public function test_over_unit_same(): void
    {
        $this->assertCount(1, $this->svc->hasOverUnit(3, self::PERM, 8)); // dc over ch3
    }

    public function test_over_unit_parent(): void
    {
        $this->assertCount(1, $this->svc->hasOverUnit(3, self::PERM, 3)); // rc over ch3
    }

    public function test_over_unit_not_in_chain_throws(): void
    {
        $this->expectExceptionMessage('Officer not found in chain');
        $this->svc->hasOverUnit(6, self::PERM, 3); // rc (region 2) over ch6
    }

    public function test_over_unit_missing_404(): void
    {
        try {
            $this->svc->hasOverUnit(999, self::PERM, 2);
            $this->fail('expected 404');
        } catch (UserException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    // --- hasOverOffice ----------------------------------------------------

    public function test_over_office_self(): void
    {
        $this->assertCount(1, $this->svc->hasOverOffice(4, self::PERM, 8)); // dc holds office 4
    }

    public function test_over_office_parent(): void
    {
        $this->assertCount(1, $this->svc->hasOverOffice(7, self::PERM, 3)); // rc (office 3) over 7
    }

    public function test_over_office_assistant_to_parent(): void
    {
        $this->assertCount(1, $this->svc->hasOverOffice(7, self::PERM, 10)); // assistant to office 3
    }

    public function test_over_office_assistant_not_parent_throws(): void
    {
        $this->expectExceptionMessage('Officer not found in chain');
        $this->svc->hasOverOffice(20, self::PERM, 10); // assistant->3, office 20 not in chain
    }

    public function test_over_office_not_parent_throws(): void
    {
        $this->expectExceptionMessage('Officer not found in chain');
        $this->svc->hasOverOffice(20, self::PERM, 8); // dc (region branch) over other-branch office
    }

    public function test_over_office_missing_404(): void
    {
        try {
            $this->svc->hasOverOffice(999, self::PERM, 2);
            $this->fail('expected 404');
        } catch (UserException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    // --- prefetch + preset office -----------------------------------------

    public function test_prefetch_returns_held_offices(): void
    {
        $offices = $this->svc->prefetch(2);
        $this->assertGreaterThan(0, $offices->count());
        $offices->each(fn (Office $o) => $this->assertSame(2, $o->userID));
    }

    public function test_prefetch_throws_for_user_without_offices(): void
    {
        $this->expectException(UserException::class);
        $this->svc->prefetch(5);
    }

    public function test_preset_office_narrows_to_held_office(): void
    {
        app(PresetOfficeContext::class)->set(1); // nc holds office 1
        $offices = app(PermissionService::class)->prefetch(2);
        $this->assertCount(1, $offices);
        $this->assertSame(1, $offices->first()->id);
    }

    public function test_preset_office_not_held_throws(): void
    {
        app(PresetOfficeContext::class)->set(1); // office 1 held by nc(2), not by user 3
        $this->expectException(UserException::class);
        app(PermissionService::class)->prefetch(3);
    }

    // --- checkOfficeRoles -------------------------------------------------

    public function test_check_roles_allows_subset(): void
    {
        $this->svc->checkOfficeRoles(['a', 'b'], ['a', 'b', 'c']);
        $this->assertTrue(true); // no throw
    }

    public function test_check_roles_cannot_add_role_officer_lacks(): void
    {
        $this->expectExceptionMessage('Cannot add role to office not held by your office');
        $this->svc->checkOfficeRoles(['a', 'z'], ['a', 'b']);
    }

    public function test_check_roles_assistant_cannot_exceed_primary(): void
    {
        $this->expectExceptionMessage('Role not in parent office');
        $this->svc->checkOfficeRoles(['a', 'b'], ['a', 'b'], currentRoles: [], primaryRoles: ['a']);
    }

    public function test_check_roles_cannot_remove_role_you_lack(): void
    {
        $this->expectExceptionMessage('Cannot remove role from office that your office does not have');
        // current has 'b'; new drops it; officer lacks 'b' -> cannot remove
        $this->svc->checkOfficeRoles(['a'], ['a'], currentRoles: ['a', 'b']);
    }
}
