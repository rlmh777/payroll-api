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
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('description', 255);
            $table->string('code1',24);
            $table->string('code2', 24)->nullable();
            $table->decimal('balance', 12, 2)->default(0);
            // Create account_type_id column without foreign key constraint
            // The foreign key will be added in a later migration after account_types table exists
            $table->unsignedBigInteger('account_type_id')->nullable();
            $table->uuid('parent_id')->nullable();
            $table->timestamps();
        });

        // Add self-referencing foreign key constraint after table is created
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('accounts')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Use CASCADE to drop dependent foreign key constraints
        if (Schema::hasTable('accounts')) {
            DB::statement('DROP TABLE IF EXISTS accounts CASCADE');
        }
    }
};
