<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A game's IANA timezone (e.g. America/New_York), so cycle dates are entered
 * and shown on the game's clock rather than whoever happens to be viewing.
 * Null means the default, America/New_York.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_units', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('org_units', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
