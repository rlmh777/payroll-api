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
        Schema::create('calendar_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('name', 128);
            $table->string('color', 32)->default('primary');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_groups');
    }
};
