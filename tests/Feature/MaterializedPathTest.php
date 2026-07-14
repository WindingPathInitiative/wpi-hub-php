<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\OrgUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit coverage for the materialized-path logic (HasMaterializedPath trait +
 * Office's overrides). Migrate.md flags this as the highest-risk port, so the
 * OrgUnit and Office prefix semantics are pinned down explicitly.
 *
 * org_units tree:
 *   1 (Nation)  "1"
 *   2 (Chapter) "1.2"
 *   3 (Chapter) "1.3"
 *   4 (Venue)   "1.3.4"
 *   5 (Venue)   "1.3.5"
 */
class MaterializedPathTest extends TestCase
{
    use RefreshDatabase;

    private function makeUnit(int $id, string $type, string $path): OrgUnit
    {
        return OrgUnit::forceCreate([
            'id' => $id,
            'name' => "unit{$id}",
            'type' => $type,
            'parentPath' => $path,
        ]);
    }

    private function seedTree(): void
    {
        $this->makeUnit(1, 'Nation', '1');
        $this->makeUnit(2, 'Chapter', '1.2');
        $this->makeUnit(3, 'Chapter', '1.3');
        $this->makeUnit(4, 'Venue', '1.3.4');
        $this->makeUnit(5, 'Venue', '1.3.5');
    }

    public function test_parents_drops_self(): void
    {
        $this->seedTree();
        $this->assertSame([1, 3], OrgUnit::find(4)->parents());
        $this->assertSame([1], OrgUnit::find(2)->parents());
        $this->assertSame([], OrgUnit::find(1)->parents()); // root has no ancestors
    }

    public function test_get_parents_loads_ancestors(): void
    {
        $this->seedTree();
        $this->assertSame([1, 3], OrgUnit::find(4)->getParents()->pluck('id')->sort()->values()->all());
        $this->assertTrue(OrgUnit::find(1)->getParents()->isEmpty());
    }

    public function test_get_children_is_dot_delimited_and_excludes_self(): void
    {
        $this->seedTree();
        $this->assertSame([4, 5], OrgUnit::find(3)->getChildren()->pluck('id')->sort()->values()->all());
        $this->assertSame([2, 3, 4, 5], OrgUnit::find(1)->getChildren()->pluck('id')->sort()->values()->all());
        $this->assertTrue(OrgUnit::find(4)->getChildren()->isEmpty());
    }

    public function test_get_chain_returns_ancestors_and_descendants_ordered_by_path(): void
    {
        $this->seedTree();
        // unit 3: ancestor [1] + descendants [4,5], ordered by parentPath asc.
        $this->assertSame([1, 4, 5], OrgUnit::find(3)->getChain()->pluck('id')->all());
    }

    public function test_is_child_accepts_ids_and_models(): void
    {
        $this->seedTree();
        $four = OrgUnit::find(4);
        $this->assertTrue($four->isChild(3));
        $this->assertTrue($four->isChild(1));
        $this->assertFalse($four->isChild(2));
        $this->assertTrue($four->isChild(OrgUnit::find(1)));
    }

    public function test_has_child_accepts_ids_and_models(): void
    {
        $this->seedTree();
        $this->assertTrue(OrgUnit::find(1)->hasChild(4));
        $this->assertTrue(OrgUnit::find(3)->hasChild(4));
        $this->assertFalse(OrgUnit::find(2)->hasChild(4));
        $this->assertTrue(OrgUnit::find(1)->hasChild(OrgUnit::find(3)));
    }

    public function test_insert_with_path_appends_new_id(): void
    {
        $this->seedTree();
        $unit = new OrgUnit;
        $unit->name = 'new venue';
        $unit->type = 'Venue';
        $unit->parentPath = OrgUnit::find(3)->parentPath; // "1.3"
        $unit->insertWithPath();

        $this->assertSame("1.3.{$unit->id}", $unit->parentPath);
        $this->assertSame("1.3.{$unit->id}", OrgUnit::find($unit->id)->parentPath);
        $this->assertTrue(OrgUnit::find(3)->getChildren()->pluck('id')->contains($unit->id));
    }

    // --- Offices: different prefix semantics (1 "1", 2 "1.2", 5 "1.5") ---

    private function makeOffice(int $id, string $path, int $orgId): Office
    {
        return Office::forceCreate([
            'id' => $id,
            'name' => "office{$id}",
            'type' => 'Primary',
            'parentOrgID' => $orgId,
            'parentPath' => $path,
            'roles' => [],
        ]);
    }

    public function test_office_get_parents_includes_self(): void
    {
        $this->makeOffice(1, '1', 1);
        $this->makeOffice(2, '1.2', 1);
        $this->makeOffice(5, '1.5', 2);

        // ancestor 1 AND self 2
        $this->assertSame([1, 2], Office::find(2)->getParents()->pluck('id')->sort()->values()->all());
    }

    public function test_office_get_children_uses_bare_prefix_including_self(): void
    {
        $this->makeOffice(1, '1', 1);
        $this->makeOffice(2, '1.2', 1);
        $this->makeOffice(5, '1.5', 2);

        // "1%" matches "1", "1.2", "1.5"
        $this->assertSame([1, 2, 5], Office::find(1)->getChildren()->pluck('id')->sort()->values()->all());
    }

    public function test_office_roles_cast_round_trips(): void
    {
        $office = $this->makeOffice(1, '1', 1);
        $office->roles = ['user_update', 'org_update'];
        $office->save();

        $this->assertSame(['user_update', 'org_update'], Office::find(1)->roles);
    }
}
