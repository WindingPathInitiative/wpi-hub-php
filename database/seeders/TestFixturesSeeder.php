<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Feature-test fixtures: the real hub_dev/beta data (database/fixtures/
 * hub_beta.json) seeded verbatim, plus a handful of explicit personas the
 * endpoint branches need that the beta snapshot lacks.
 *
 * The beta members all expire 2021-01-01, so feature tests freeze the clock to
 * a date when that data is valid (see Tests\Concerns\UsesBetaFixtures).
 *
 * Persona ids are >=100 to avoid colliding with the beta rows (max id 38).
 */
class TestFixturesSeeder extends Seeder
{
    /** Clock the feature tests should freeze to (beta data is valid here). */
    public const FROZEN_NOW = '2020-06-01 12:00:00';

    public const EXPIRED = 100;

    public const SUSPENDED = 101;

    public const CHAPTERLESS = 102;

    public const PENDING = 103;

    public const ADMIN = 104;

    /** National officer (Club Manager, office 1) from the beta data. */
    public const NC = 2;

    public function run(): void
    {
        $data = json_decode(
            file_get_contents(database_path('fixtures/hub_beta.json')),
            true
        );

        foreach (['org_units', 'users', 'offices', 'tokens'] as $table) {
            DB::table($table)->delete();
            if (! empty($data[$table])) {
                foreach (array_chunk($data[$table], 50) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        }

        // Added personas (org 2 = "Role Initiative Philadelphia", a Chapter).
        DB::table('users')->insert([
            $this->persona(self::EXPIRED, 'Expired', 'Member', 'Full', '2019-01-01', 2),
            $this->persona(self::SUSPENDED, 'Suspended', 'Member', 'Suspended', '2021-01-01', 2),
            $this->persona(self::CHAPTERLESS, 'Chapterless', 'Member', 'Full', '2021-01-01', null),
            $this->persona(self::PENDING, 'Pending', 'Member', 'Pending', '2021-01-01', null),
            $this->persona(self::ADMIN, 'Admin', 'Persona', 'Full', '2021-01-01', 1),
        ]);

        // No beta office carries the 'admin' role; add one for the admin persona
        // so the admin-gated endpoints (e.g. POST /office) are testable.
        DB::table('offices')->insert([
            'id' => 110,
            'name' => 'Administrator',
            'type' => 'Primary',
            'email' => null,
            'parentOfficeID' => null,
            'parentPath' => '1',
            'parentOrgID' => 1,
            'userID' => self::ADMIN,
            'roles' => json_encode(['admin']),
        ]);
    }

    private function persona(int $id, string $first, string $last, string $type, string $expires, ?int $org): array
    {
        return [
            'id' => $id,
            'portalID' => null,
            'firstName' => $first,
            'lastName' => $last,
            'nickname' => "{$first} {$last}",
            'address' => null,
            'email' => strtolower($first).'.persona@example.com',
            'membershipType' => $type,
            'membershipNumber' => 'WPI2019'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'membershipExpiration' => $expires,
            'orgUnit' => $org,
        ];
    }
}
