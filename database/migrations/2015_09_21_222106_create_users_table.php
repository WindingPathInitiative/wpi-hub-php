<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ports wpi-hub migrations/20150921222106_users.js (+ the membershipType enum
 * evolution from the 2018 migrations) to the final live schema. Column names
 * stay camelCase to preserve the external Portal/audit contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('portalID', 36)->nullable()->unique('users_portalid_unique');
            $table->string('firstName');
            $table->string('lastName');
            $table->string('nickname')->nullable();
            $table->text('address')->nullable();
            $table->string('email')->index('users_email_index');
            $table->enum('membershipType', [
                'None', 'Pending', 'Uninvited', 'Full', 'Suspended', 'Expelled', 'Resigned',
            ])->default('None');
            $table->string('membershipNumber', 15)->index('users_membershipnumber_index');
            $table->date('membershipExpiration')->index('users_membershipexpiration_index');
            $table->integer('orgUnit')->nullable()->index('users_orgunit_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
