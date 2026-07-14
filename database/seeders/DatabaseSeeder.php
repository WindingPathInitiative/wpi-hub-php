<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Default seed data, ported from wpi-hub seeds/*.default.js.
 *
 * (The richer fixture hierarchy that the legacy HTTP test suite assumes is
 * handled separately once the test-porting approach is settled.)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            OrgUnitsSeeder::class,
            UsersSeeder::class,
            OfficesSeeder::class,
        ]);
    }
}
