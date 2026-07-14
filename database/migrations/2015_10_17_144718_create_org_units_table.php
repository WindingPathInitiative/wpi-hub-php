<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ports wpi-hub migrations/20151017144718_org_units.js (+ the Domain->Chapter
 * enum change). `type` ended up nullable in the live schema after the .alter()
 * in the DomainToChapter migration, so it is nullable here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->nullable()->index('org_units_code_index');
            $table->string('venueType')->nullable();
            $table->string('location')->nullable();
            $table->string('website')->nullable();
            $table->enum('type', ['Venue', 'Chapter', 'Region', 'Nation'])
                ->nullable()
                ->index('org_units_type_index');
            $table->text('defDoc')->nullable();
            $table->string('parentPath')->index('org_units_parentpath_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_units');
    }
};
