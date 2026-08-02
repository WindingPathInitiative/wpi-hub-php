<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Character storage for the Sporebound character wizard and the advancement /
 * downtime cycle (see sporebound-character "advancedments-plan.md").
 *
 *   characters            -> the v2 character envelope plus ownership/org links
 *   cycles                -> a game's between-sessions period; grants improvements
 *   character_submissions -> a batch of Change envelopes awaiting ST approval
 *
 * The v2 envelope in `character_json` is {formatVersion, identity, starting,
 * changes, current}: the starting character plus the ordered list of approved
 * changes. `current_json` caches the resolved sheet; it is computed client-side
 * (the replay engine is TypeScript) and stored here opaquely, so it is a cache
 * and never the authority — starting + changes always is.
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
            // Optional while a character is merely saved; required to submit it.
            $table->unsignedInteger('org_unit_id')->nullable()->index('characters_org_unit_id_index');
            $table->json('character_json');
            $table->json('current_json')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cycles', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('org_unit_id')->index('cycles_org_unit_id_index');
            $table->string('name');
            // Game one's cycle grants 0; every later one grants 2 by default.
            $table->unsignedInteger('improvements_granted')->default(2);
            $table->timestamp('opens_at');
            $table->timestamp('downtime_due_at')->nullable();
            $table->timestamps();
        });

        Schema::create('character_submissions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('character_id')->index('character_submissions_character_id_index');
            // Null for rewrites/adjustments/ST edits, which are not cycle-bound.
            $table->unsignedInteger('cycle_id')->nullable()->index('character_submissions_cycle_id_index');
            // advancement | downtime | adjustment | rewrite | storyteller
            $table->string('type', 20);
            // draft | submitted | approved | rejected
            $table->string('status', 20)->default('draft')->index('character_submissions_status_index');
            $table->json('changes_json');
            // The sheet this submission resolves to, per the client's replay.
            $table->json('current_json')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_submissions');
        Schema::dropIfExists('cycles');
        Schema::dropIfExists('characters');
    }
};
