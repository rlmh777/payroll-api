<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_compensation', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_compensation', 'weeklyRate')) {
                $table->decimal('weeklyRate', 12, 2)->default(0)->after('hourlyRate');
            }
        });

        DB::table('employee_compensation')
            ->where('compensationMethod', 'BASE_SALARY')
            ->update(['compensationMethod' => 'SALARY_NO_CLOCK']);
    }

    public function down(): void
    {
        DB::table('employee_compensation')
            ->where('compensationMethod', 'SALARY_NO_CLOCK')
            ->update(['compensationMethod' => 'BASE_SALARY']);

        Schema::table('employee_compensation', function (Blueprint $table) {
            if (Schema::hasColumn('employee_compensation', 'weeklyRate')) {
                $table->dropColumn('weeklyRate');
            }
        });
    }
};
