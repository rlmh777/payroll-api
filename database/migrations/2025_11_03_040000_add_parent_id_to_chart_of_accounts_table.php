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
        // Check if parent_id column already exists (it may have been created in the original migration)
        if (!Schema::hasColumn('accounts', 'parent_id')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->uuid('parent_id')->nullable()->after('account_type_id');
            });
        }

        // Add foreign key constraint if it doesn't already exist
        try {
            Schema::table('accounts', function (Blueprint $table) {
                $table->foreign('parent_id')->references('id')->on('accounts')->onDelete('cascade');
            });
        } catch (\Exception $e) {
            // Foreign key constraint might already exist, continue
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};

