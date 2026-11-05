<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A grace period after a cycle's downtime deadline: late downtime is still
 * accepted until it ends, but with no Investigation and only the Downtime
 * Actions that don't move against another character's Anchors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cycles', function (Blueprint $table) {
            $table->timestamp('downtime_grace_ends_at')->nullable()->after('downtime_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('cycles', function (Blueprint $table) {
            $table->dropColumn('downtime_grace_ends_at');
        });
    }
};
