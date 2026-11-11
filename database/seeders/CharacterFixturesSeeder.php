<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures for the character/advancement feature tests.
 *
 * Deliberately self-contained (unlike TestFixturesSeeder, which replays the
 * private beta snapshot): a two-game hierarchy with a storyteller each, so the
 * "storyteller of another game" denial is testable.
 *
 *   org 1 Nation -> org 2 Chapter -> org 3 Venue (GAME) and org 4 Venue (OTHER_GAME)
 *   office 1 storytells GAME, office 2 storytells OTHER_GAME, office 3 is a
 *   chapter office with character_approve (so ancestor scoping is testable).
 *
 *   org 5 Venue (CON_GAME, code SPOREBOUND-CON-PAX) hangs straight off the
 *   Nation; office 5 storytells it. (A national officer would storytell every
 *   game and break the "no storyteller" mail tests, so ConventionCharacterTest
 *   adds its own.)
 */
class CharacterFixturesSeeder extends Seeder
{
    /** Clock the character tests freeze to. */
    public const FROZEN_NOW = '2026-06-01 12:00:00';

    public const NATION = 1;

    public const CHAPTER = 2;

    public const GAME = 3;

    public const OTHER_GAME = 4;

    /** A convention venue: code contains -CON-. */
    public const CON_GAME = 5;

    public const PLAYER = 1;

    public const OTHER_PLAYER = 2;

    public const ST = 3;

    public const OTHER_ST = 4;

    public const CHAPTER_ST = 5;

    /** Holds an office with no character role at all. */
    public const BYSTANDER = 6;

    public const CON_ST = 7;

    public function run(): void
    {
        DB::table('org_units')->insert([
            $this->unit(self::NATION, 'Winding Path Initiative', 'Nation', '1'),
            $this->unit(self::CHAPTER, 'Test Chapter', 'Chapter', '1.2'),
            $this->unit(self::GAME, 'Sporebound Test Game', 'Venue', '1.2.3'),
            $this->unit(self::OTHER_GAME, 'Sporebound Other Game', 'Venue', '1.2.4'),
            ['code' => 'SPOREBOUND-CON-PAX'] + $this->unit(self::CON_GAME, 'Sporebound PAX', 'Venue', '1.5'),
        ]);

        DB::table('users')->insert([
            $this->user(self::PLAYER, 'Player', 'One', self::GAME),
            $this->user(self::OTHER_PLAYER, 'Player', 'Two', self::GAME),
            $this->user(self::ST, 'Story', 'Teller', self::GAME),
            $this->user(self::OTHER_ST, 'Other', 'Teller', self::OTHER_GAME),
            $this->user(self::CHAPTER_ST, 'Chapter', 'Teller', self::CHAPTER),
            $this->user(self::BYSTANDER, 'By', 'Stander', self::GAME),
            $this->user(self::CON_ST, 'Con', 'Teller', self::CON_GAME),
        ]);

        DB::table('offices')->insert([
            $this->office(1, 'Game Storyteller', self::GAME, self::ST, ['character_approve']),
            $this->office(2, 'Other Game Storyteller', self::OTHER_GAME, self::OTHER_ST, ['character_approve']),
            $this->office(3, 'Chapter Owner', self::CHAPTER, self::CHAPTER_ST, ['character_approve', 'org_update']),
            $this->office(4, 'Game Assistant', self::GAME, self::BYSTANDER, ['checkin_event_checkin']),
            $this->office(5, 'Convention Storyteller', self::CON_GAME, self::CON_ST, ['character_approve']),
        ]);
    }

    private function unit(int $id, string $name, string $type, string $path): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'code' => null,
            'venueType' => $type === 'Venue' ? 'Nationally Directed' : null,
            'location' => null,
            'website' => null,
            'type' => $type,
            'defDoc' => null,
            'parentPath' => $path,
        ];
    }

    private function user(int $id, string $first, string $last, int $org): array
    {
        return [
            'id' => $id,
            'portalID' => null,
            'firstName' => $first,
            'lastName' => $last,
            'nickname' => "{$first} {$last}",
            'address' => null,
            'email' => strtolower($first.$last).'@example.com',
            'membershipType' => 'Full',
            'membershipNumber' => 'WPI2026'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'membershipExpiration' => '2027-01-01',
            'orgUnit' => $org,
        ];
    }

    private function office(int $id, string $name, int $org, int $user, array $roles): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'email' => null,
            'type' => 'Primary',
            'parentOfficeID' => null,
            'parentPath' => (string) $id,
            'parentOrgID' => $org,
            'userID' => $user,
            'roles' => json_encode($roles),
        ];
    }
}
