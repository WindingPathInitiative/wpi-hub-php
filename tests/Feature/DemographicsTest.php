<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterSubmission;
use App\Models\Cycle;
use Database\Seeders\CharacterFixturesSeeder as F;
use Tests\CharacterTestCase;

/**
 * GET /org-unit/{unit}/demographics: the storyteller's who's-who of a game.
 */
class DemographicsTest extends CharacterTestCase
{
    private const URI = '/v1/org-unit/'.F::GAME.'/demographics';

    public function test_needs_character_approve_over_the_game(): void
    {
        $this->makeCharacter();

        $this->getJsonAs(F::PLAYER, self::URI)->assertStatus(403);
        $this->getJsonAs(F::BYSTANDER, self::URI)->assertStatus(403);
        $this->getJsonAs(F::OTHER_ST, self::URI)->assertStatus(403);

        $this->getJsonAs(F::ST, self::URI)->assertOk();
        $this->getJsonAs(F::CHAPTER_ST, self::URI)->assertOk();
    }

    public function test_includes_retired_characters(): void
    {
        $this->makeCharacter(['name' => 'Nicki']);
        $this->makeCharacter(['name' => 'Gone', 'retired_at' => now()]);
        $this->makeCharacter(['name' => 'Elsewhere', 'org_unit_id' => F::OTHER_GAME]);

        $res = $this->getJsonAs(F::ST, self::URI)->assertOk();

        $this->assertSame(['Gone', 'Nicki'], array_column($res->json('characters'), 'name'));
        $this->assertNotNull($res->json('characters.0.retired_at'));
        $this->assertNull($res->json('characters.1.retired_at'));
    }

    public function test_projects_clade_bloom_and_active_anchors_only(): void
    {
        $sheet = $this->sheet();
        $sheet['bloomId'] = 'class-composters';
        $sheet['anchors'] = [
            ['anchorId' => 'a1', 'type' => 'group', 'name' => 'Mutual Aid', 'tag' => 'underdog', 'category' => 'Non-profit', 'active' => true],
            ['anchorId' => 'a2', 'type' => 'individual', 'name' => 'Marcus', 'tag' => 'affluent', 'category' => 'Business'],
            ['anchorId' => 'a3', 'type' => 'group', 'name' => 'Burned Down', 'tag' => 'underdog', 'category' => 'Culture', 'active' => false],
        ];
        $this->makeCharacter(['current_json' => $sheet]);

        $row = $this->getJsonAs(F::ST, self::URI)->assertOk()->json('characters.0');

        $this->assertSame('dumpster', $row['clade_id']);
        $this->assertSame('class-composters', $row['bloom_id']);
        $this->assertSame(['Mutual Aid', 'Marcus'], array_column($row['anchors'], 'name'));
        $this->assertSame(
            ['type' => 'group', 'name' => 'Mutual Aid', 'tag' => 'underdog', 'category' => 'Non-profit'],
            $row['anchors'][0],
        );
        // The full sheet stays on the server.
        $this->assertArrayNotHasKey('current_json', $row);
        $this->assertArrayNotHasKey('attributes', $row);
    }

    public function test_bloom_is_null_when_absent(): void
    {
        $this->makeCharacter();

        $row = $this->getJsonAs(F::ST, self::URI)->assertOk()->json('characters.0');

        $this->assertNull($row['bloom_id']);
    }

    public function test_downtime_status_for_the_current_cycle(): void
    {
        $cycle = $this->makeCycle();
        $none = $this->makeCharacter(['name' => 'A None']);
        $draft = $this->makeCharacter(['name' => 'B Draft']);
        $submitted = $this->makeCharacter(['name' => 'C Submitted']);
        $approved = $this->makeCharacter(['name' => 'D Approved']);
        $rejected = $this->makeCharacter(['name' => 'E Rejected']);
        $resubmitted = $this->makeCharacter(['name' => 'F Approved after rejection']);

        $this->downtimeFor($draft, $cycle, 'draft');
        $sub = $this->downtimeFor($submitted, $cycle, 'submitted');
        $this->downtimeFor($approved, $cycle, 'approved');
        $this->downtimeFor($rejected, $cycle, 'rejected');
        $this->downtimeFor($resubmitted, $cycle, 'rejected');
        $winner = $this->downtimeFor($resubmitted, $cycle, 'approved');
        $this->downtimeFor($resubmitted, $cycle, 'rejected');

        $res = $this->getJsonAs(F::ST, self::URI)->assertOk();
        $rows = collect($res->json('characters'))->keyBy('name');

        $this->assertSame($cycle->id, $res->json('cycle.id'));
        $this->assertNull($rows['A None']['downtime']);
        $this->assertNull($rows['B Draft']['downtime']);
        $this->assertSame('submitted', $rows['C Submitted']['downtime']['status']);
        $this->assertSame($sub->id, $rows['C Submitted']['downtime']['submission_id']);
        $this->assertNotNull($rows['C Submitted']['downtime']['submitted_at']);
        $this->assertSame('approved', $rows['D Approved']['downtime']['status']);
        $this->assertSame('rejected', $rows['E Rejected']['downtime']['status']);
        $this->assertSame($winner->id, $rows['F Approved after rejection']['downtime']['submission_id']);
    }

    public function test_only_the_current_cycle_counts(): void
    {
        $previous = $this->makeCycle(['name' => 'Previous', 'opens_at' => now()->subDays(30), 'downtime_due_at' => now()->subDays(20)]);
        $current = $this->makeCycle(['name' => 'Current']);
        $character = $this->makeCharacter();
        $this->downtimeFor($character, $previous, 'approved');

        $res = $this->getJsonAs(F::ST, self::URI)->assertOk();

        $this->assertSame($current->id, $res->json('cycle.id'));
        $this->assertNull($res->json('characters.0.downtime'));
    }

    public function test_no_cycle_when_none_has_opened(): void
    {
        $future = $this->makeCycle(['name' => 'Scheduled', 'opens_at' => now()->addDays(3), 'downtime_due_at' => now()->addDays(10)]);
        $character = $this->makeCharacter();
        $this->downtimeFor($character, $future, 'submitted');

        $res = $this->getJsonAs(F::ST, self::URI)->assertOk();

        $this->assertNull($res->json('cycle'));
        $this->assertNull($res->json('characters.0.downtime'));
    }

    private function downtimeFor(Character $character, Cycle $cycle, string $status): CharacterSubmission
    {
        return CharacterSubmission::create([
            'character_id' => $character->id,
            'cycle_id' => $cycle->id,
            'type' => 'downtime',
            'status' => $status,
            'changes_json' => $this->downtime(),
            'submitted_at' => $status === 'draft' ? null : now(),
        ]);
    }
}
