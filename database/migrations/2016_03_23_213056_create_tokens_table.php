<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ports wpi-hub migrations/20160323213056_tokens.js. Legacy dev/switch tokens;
 * kept for parity (and the upcoming Keycloak transition).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens', function (Blueprint $table) {
            $table->uuid('token')->primary();
            $table->integer('user')->index('tokens_user_index');
            $table->dateTime('expires')->index('tokens_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens');
    }
};
