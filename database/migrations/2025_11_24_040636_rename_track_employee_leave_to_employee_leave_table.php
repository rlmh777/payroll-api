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
        if (Schema::hasTable('track_employee_leave') && !Schema::hasTable('employee_leave')) {
            // Rename the table - PostgreSQL will automatically rename dependent constraints
            Schema::rename('track_employee_leave', 'employee_leave');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('employee_leave') && !Schema::hasTable('track_employee_leave')) {
            // PostgreSQL automatically renames constraints when renaming a table
            // So we just need to rename the table back
            Schema::rename('employee_leave', 'track_employee_leave');
        }
    }
};
