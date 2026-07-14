<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ports wpi-hub seeds/users.default.js. Every row carries the same column set
 * (portalID null where the source omitted it) because Laravel's multi-row
 * insert requires a uniform column list.
 */
class UsersSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->delete();

        DB::table('users')->insert([
            [
                'id' => 1,
                'portalID' => '69dfb3c2-d61d-49da-b8b0-98b18ef18a54',
                'firstName' => 'Joe',
                'lastName' => 'Terranova',
                'nickname' => 'Joe Terranova',
                'email' => 'joeterranova@gmail.com',
                'membershipType' => 'Full',
                'membershipNumber' => 'WPI20180004',
                'membershipExpiration' => '2020-01-01',
                'orgUnit' => 2,
            ],
            [
                'id' => 2,
                'portalID' => null,
                'firstName' => 'Jeremy',
                'lastName' => 'White',
                'nickname' => 'Jeremy White',
                'email' => 'smackhammer@gmail.com',
                'membershipType' => 'Full',
                'membershipNumber' => 'WPI20180002',
                'membershipExpiration' => '2020-01-01',
                'orgUnit' => 2,
            ],
            [
                'id' => 3,
                'portalID' => null,
                'firstName' => 'Jaclyn',
                'lastName' => 'Terranova',
                'nickname' => 'Jaclyn Terranova',
                'email' => 'jaclyn.terranova@gmail.com ',
                'membershipType' => 'Full',
                'membershipNumber' => 'WPI20180003',
                'membershipExpiration' => '2020-01-01',
                'orgUnit' => 2,
            ],
        ]);
    }
}
