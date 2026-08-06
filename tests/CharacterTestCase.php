<?php

namespace Tests;

use App\Models\Character;
use App\Models\Cycle;
use Database\Seeders\CharacterFixturesSeeder as F;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * Base for the character/advancement feature tests. Uses the self-contained
 * CharacterFixturesSeeder rather than the beta snapshot, so these tests run
 * anywhere. Authentication uses the numeric Authorization dev bypass.
 */
abstract class CharacterTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(F::class);
        $this->travelTo(F::FROZEN_NOW);
    }

    protected function asUser(int $id): static
    {
        return $this->withHeader('Authorization', (string) $id);
    }

    protected function getJsonAs(int $id, string $uri): TestResponse
    {
        return $this->asUser($id)->getJson($uri);
    }

    /**
     * A saved character with a minimal v2 envelope and resolved sheet.
     */
    protected function makeCharacter(array $attributes = []): Character
    {
        $sheet = $attributes['current_json'] ?? $this->sheet();

        return Character::create(array_merge([
            'name' => 'Nicki Parker',
            'user_id' => F::PLAYER,
            'org_unit_id' => F::GAME,
            'character_json' => [
                'formatVersion' => 2,
                'identity' => ['characterName' => 'Nicki Parker', 'playerName' => 'Player One'],
                'starting' => ['cladeId' => 'dumpster'],
                'changes' => [],
                'current' => $sheet,
            ],
            'current_json' => $sheet,
        ], $attributes));
    }

    /**
     * A resolved sheet with two Anchors and one Germ on the first.
     */
    protected function sheet(): array
    {
        return [
            'cladeId' => 'dumpster',
            'hp' => 10,
            'attributes' => ['body' => 5, 'mind' => 6, 'finesse' => 4, 'charisma' => 3],
            'trainedSkills' => ['empathy'],
            'germs' => [['id' => 'shroom-shield', 'cultured' => false, 'anchorId' => 'a1']],
            'anchors' => [
                ['anchorId' => 'a1', 'type' => 'group', 'name' => 'The Crew', 'active' => true],
                ['anchorId' => 'a2', 'type' => 'individual', 'name' => 'Marcus', 'active' => true],
            ],
        ];
    }

    /**
     * An opened cycle for the test game.
     */
    protected function makeCycle(array $attributes = []): Cycle
    {
        return Cycle::create(array_merge([
            'org_unit_id' => F::GAME,
            'name' => 'Game 1 -> 2',
            'improvements_granted' => 2,
            'opens_at' => now()->subDays(7),
            'downtime_due_at' => now()->addDays(7),
        ], $attributes));
    }

    /**
     * A well-formed downtime: the Investigation every character files, plus the
     * one Downtime Action they chose.
     *
     * @return array<int, array>
     */
    protected function downtime(string $action = 'downtime.develop-anchor'): array
    {
        return $this->changes(['downtime.investigation', $action]);
    }

    /**
     * @param  string[]  $kinds
     * @return array<int, array>
     */
    protected function changes(array $kinds): array
    {
        return array_map(fn (string $kind) => match ($kind) {
            'advancement.card' => ['kind' => 'advancement.card', 'payload' => ['increaseCardId' => 'attr-body']],
            'advancement.skill' => ['kind' => 'advancement.skill', 'payload' => ['skillId' => 'investigate']],
            'advancement.germ' => ['kind' => 'advancement.germ', 'payload' => ['germId' => 'mycelial-senses', 'anchorId' => 'a1']],
            'vxp.purchase' => ['kind' => 'vxp.purchase', 'payload' => ['cost' => 20]],
            'downtime.investigation' => ['kind' => 'downtime.investigation', 'payload' => [
                'topic' => 'Who torched the warehouse on Delaware Ave?', 'anchorId' => null,
            ]],
            'downtime.investigate' => ['kind' => 'downtime.investigate', 'payload' => [
                'topic' => 'Where did the missing shift nurse go?', 'anchorId' => 'a1',
            ]],
            'downtime.develop-anchor' => ['kind' => 'downtime.develop-anchor', 'payload' => [
                'anchor' => ['type' => 'group', 'name' => 'New Friends', 'tag' => 'underdog'],
            ]],
            'downtime.protect-anchor' => ['kind' => 'downtime.protect-anchor', 'payload' => ['anchorId' => 'a1']],
            'downtime.undermine-anchor' => ['kind' => 'downtime.undermine-anchor', 'payload' => [
                'target' => 'Ray Vasquez — his union local', 'describe' => 'Rumours in the right ears',
            ]],
            'downtime.destroy-anchor' => ['kind' => 'downtime.destroy-anchor', 'payload' => [
                'target' => 'Ray Vasquez — his union local', 'describe' => 'They scatter for good',
            ]],
            'downtime.narrative-impact' => ['kind' => 'downtime.narrative-impact', 'payload' => [
                'describe' => 'Opens a free clinic in Kensington',
            ]],
            'downtime.culture-germ' => ['kind' => 'downtime.culture-germ', 'payload' => ['germId' => 'shroom-shield']],
            'adjust.germ-assignment' => ['kind' => 'adjust.germ-assignment', 'payload' => ['germId' => 'shroom-shield', 'anchorId' => 'a2']],
            'adjust.face-cards' => ['kind' => 'adjust.face-cards', 'payload' => ['faceCardSuits' => ['A' => 'spades']]],
            default => ['kind' => $kind, 'payload' => []],
        }, $kinds);
    }
}
