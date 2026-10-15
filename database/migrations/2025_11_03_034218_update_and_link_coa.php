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
        // Check if account_type_id column already exists (it was created in the original migration)
        if (!Schema::hasColumn('accounts', 'account_type_id')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->unsignedBigInteger('account_type_id')->nullable();
            });
        }

        // Add foreign key constraint if account_types table exists and constraint doesn't exist
        if (Schema::hasTable('account_types')) {
            try {
                Schema::table('accounts', function (Blueprint $table) {
                    $table->foreign('account_type_id')->references('id')->on('account_types')->onDelete('cascade');
                });
            } catch (\Exception $e) {
                // Foreign key constraint might already exist, continue
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only drop if this migration actually created the column
        // Since the original migration already creates account_type_id, 
        // we should not drop it here to avoid breaking the original migration rollback
        // This migration is essentially a no-op if the column already exists
        if (Schema::hasColumn('accounts', 'account_type_id')) {
            try {
                Schema::table('accounts', function (Blueprint $table) {
                    // Try to drop foreign key - use the standard Laravel naming convention
                    $table->dropForeign(['account_type_id']);
                });
            } catch (\Exception $e) {
                // Foreign key might not exist or have different name, continue
            }
            
            // Only drop column if this migration created it (not the original)
            // Since original migration already has it, we won't drop it here
        }
    }
};
