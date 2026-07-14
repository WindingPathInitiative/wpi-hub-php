<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ports wpi-hub seeds/offices.default.js. roles is stored as a JSON-encoded
 * string in the TEXT column (matching the Node JSON.stringify seed). Every row
 * carries the same column set (null where the source omitted it) for Laravel's
 * uniform multi-row insert.
 */
class OfficesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('offices')->delete();

        DB::table('offices')->insert([
            [
                'id' => 1,
                'name' => 'Club Manager',
                'type' => 'Primary',
                'email' => 'club.manager@windingpath.club',
                'parentOfficeID' => null,
                'parentOrgID' => 1,
                'parentPath' => '1',
                'userID' => 2,
                'roles' => json_encode([
                    'user_read_private', 'user_add', 'user_update', 'user_approve',
                    'user_assign', 'user_suspend', 'org_update', 'office_update',
                    'office_assign', 'office_create_assistants',
                    'office_create_own_assistants', 'org_create_chapter', 'org_create_venue',
                ]),
            ],
            [
                'id' => 2,
                'name' => 'Creative Director',
                'type' => 'Assistant',
                'email' => 'creative.director@windingpath.club',
                'parentOfficeID' => 1,
                'parentOrgID' => 1,
                'parentPath' => '1.2',
                'userID' => 1,
                'roles' => json_encode([
                    'user_read_private', 'office_create_assistants', 'office_create_own_assistants',
                ]),
            ],
            [
                'id' => 5,
                'name' => 'Chapter Owner',
                'type' => 'Primary',
                'email' => null,
                'parentOfficeID' => null,
                'parentOrgID' => 2,
                'parentPath' => '1.5',
                'userID' => 3,
                'roles' => json_encode([
                    'user_read_private', 'user_update', 'user_assign', 'org_update',
                    'office_update', 'office_assign', 'office_create_assistants', 'org_create_venue',
                ]),
            ],
        ]);
    }
}
