<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Character storage for the Sporebound character wizard (see "Character Plan.md").
 *
 *   characters   -> the starting character export plus ownership/org links
 *   improvements -> per-character advancement/downtime changes awaiting approval
 *
 * FK columns are unsignedInteger to match users/org_units, which use
 * increments('id') (unsigned int, not bigint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('user_id')->index('characters_user_id_index');
            $table->unsignedInteger('org_unit_id')->nullable()->index('characters_org_unit_id_index');
            $table->json('character_json');
            $table->timestamps();
        });

        Schema::create('improvements', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('character_id')->index('improvements_character_id_index');
            $table->timestamp('date_added')->useCurrent();
            $table->timestamp('date_approved')->nullable();
            $table->json('improvements_json');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvements');
        Schema::dropIfExists('characters');
    }
};
