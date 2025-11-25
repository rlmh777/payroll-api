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
            // Rename foreign key constraints back
            try {
                DB::statement('ALTER TABLE employee_leave RENAME CONSTRAINT employee_leave_employeeid_foreign TO track_employee_leave_employeeid_foreign');
            } catch (\Exception $e) {
                // Ignore if constraint doesn't exist
            }
            
            try {
                DB::statement('ALTER TABLE employee_leave RENAME CONSTRAINT employee_leave_leavetypeid_foreign TO track_employee_leave_leavetypeid_foreign');
            } catch (\Exception $e) {
                // Ignore if constraint doesn't exist
            }
            
            // Rename the table back
            Schema::rename('employee_leave', 'track_employee_leave');
        }
    }
};
