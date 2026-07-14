<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ports wpi-hub migrations/20151017144714_offices.js. `roles` is a TEXT column
 * holding a JSON-encoded array (Eloquent casts it to array on the model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->enum('type', ['Primary', 'Assistant']);
            $table->integer('parentOfficeID')->nullable()->index('offices_parentofficeid_index');
            $table->string('parentPath')->index('offices_parentpath_index');
            $table->integer('parentOrgID')->index('offices_parentorgid_index');
            $table->integer('userID')->nullable()->index('offices_userid_index');
            $table->text('roles')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offices');
    }
};
