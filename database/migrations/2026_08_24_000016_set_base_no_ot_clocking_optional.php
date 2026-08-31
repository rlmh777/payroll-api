<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_compensation') || ! Schema::hasColumn('employee_compensation', 'requiresClocking')) {
            return;
        }

        DB::table('employee_compensation')
            ->whereIn('compensationMethod', ['BASE_NO_OT', 'SALARY_NO_CLOCK', 'BASE_SALARY', 'WEEKLY_SALARY'])
            ->update(['requiresClocking' => false]);
    }

    public function down(): void
    {
        // Keep clocking optional for existing base-rate records.
    }
};
