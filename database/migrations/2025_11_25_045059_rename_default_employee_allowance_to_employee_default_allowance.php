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
        // If the old table exists, rename it to the new name
        if (Schema::hasTable('default_employee_allowance') && !Schema::hasTable('employee_default_allowance')) {
            Schema::rename('default_employee_allowance', 'employee_default_allowance');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // If the new table exists, rename it back to the old name
        if (Schema::hasTable('employee_default_allowance') && !Schema::hasTable('default_employee_allowance')) {
            Schema::rename('employee_default_allowance', 'default_employee_allowance');
        }
    }
};
