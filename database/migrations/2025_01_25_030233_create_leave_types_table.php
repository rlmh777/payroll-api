<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_type', function (Blueprint $table) {
            $table->id('id')->primary();
            $table->string('name', 255)->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Use CASCADE to drop dependent foreign key constraints
        if (Schema::hasTable('leave_type')) {
            DB::statement('DROP TABLE IF EXISTS leave_type CASCADE');
        }
    }
};
