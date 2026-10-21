<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_day_work')) {
            return;
        }

        Schema::table('employee_day_work', function (Blueprint $table) {
            if (Schema::hasColumn('employee_day_work', 'employeeId')
                && $this->indexExists('employee_day_work', 'employee_day_work_employee_date_unique')) {
                $table->dropUnique('employee_day_work_employee_date_unique');
            }

            if (! Schema::hasColumn('employee_day_work', 'guest')) {
                $table->string('guest', 255)->nullable()->after('note');
            }

            if (! Schema::hasColumn('employee_day_work', 'destination')) {
                $table->string('destination', 255)->nullable()->after('guest');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_day_work')) {
            return;
        }

        Schema::table('employee_day_work', function (Blueprint $table) {
            if (Schema::hasColumn('employee_day_work', 'destination')) {
                $table->dropColumn('destination');
            }

            if (Schema::hasColumn('employee_day_work', 'guest')) {
                $table->dropColumn('guest');
            }

            if (! $this->indexExists('employee_day_work', 'employee_day_work_employee_date_unique')) {
                $table->unique(['employeeId', 'date'], 'employee_day_work_employee_date_unique');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?', [$table, $index]);

        return $rows !== [];
    }
};
