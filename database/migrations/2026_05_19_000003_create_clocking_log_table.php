<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clocking_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('biometricUserId');
            $table->string('deviceId')->nullable();
            $table->dateTime('punchDateTime');
            $table->timestamps();

            $table->index(['biometricUserId', 'punchDateTime']);
            $table->index(['deviceId', 'punchDateTime']);
            $table->unique(['biometricUserId', 'deviceId', 'punchDateTime'], 'clocking_log_unique_entry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clocking_log');
    }
};

