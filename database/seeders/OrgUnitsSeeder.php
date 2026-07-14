<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ports wpi-hub seeds/org-units.default.js.
 */
class OrgUnitsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('org_units')->delete();

        DB::table('org_units')->insert([
            [
                'id' => 1,
                'name' => 'United States',
                'code' => 'WPI-US',
                'website' => 'https://windingpath.club',
                'type' => 'Nation',
                'location' => 'United States',
                'defDoc' => 'United States',
                'parentPath' => '1',
            ],
            [
                'id' => 2,
                'name' => 'Role Initiative Philadelphia',
                'code' => 'WPI-PA-001',
                'type' => 'Chapter',
                'website' => 'https://phillylarp.com/',
                'location' => 'Philadelphia, PA',
                'defDoc' => 'City of Philadelphia and metropolitan area, New Jersey',
                'parentPath' => '1.2',
            ],
        ]);
    }
}
