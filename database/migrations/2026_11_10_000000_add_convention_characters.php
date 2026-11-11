<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convention characters (sporebound-character "convention-plan.md"): pre-generated
 * one-shot characters a storyteller team manages in bulk for a convention venue.
 *
 *   kind        -> 'standard' (a player's v2 envelope) | 'convention' (a
 *                  ConventionCharacter document in character_json)
 *   user_id     -> nullable: convention characters belong to no player
 *   created_by  -> the storyteller who imported/created a convention row
 *   sheet_code  -> the convention sheet's stable key ("THM 1"), denormalized
 *                  from the document so import can upsert on it
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('kind', 20)->default('standard')->after('id')->index('characters_kind_index');
            $table->unsignedInteger('user_id')->nullable()->change();
            $table->unsignedInteger('created_by')->nullable()->after('user_id');
            $table->string('sheet_code', 40)->nullable()->after('name');
            $table->unique(['org_unit_id', 'sheet_code'], 'characters_org_unit_sheet_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropUnique('characters_org_unit_sheet_code_unique');
            $table->dropColumn(['kind', 'created_by', 'sheet_code']);
        });
        // user_id is left nullable: convention rows would violate NOT NULL, and
        // reverting it is only safe once they have been deleted by hand.
    }
};
