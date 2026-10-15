<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_compensation') && !Schema::hasColumn('employee_compensation', 'employmentDetailId')) {
            Schema::table('employee_compensation', function (Blueprint $table) {
                $table->foreignUuid('employmentDetailId')
                    ->nullable()
                    ->after('employeeId')
                    ->constrained('employment_detail')
                    ->nullOnDelete();
            });

            DB::statement('
                UPDATE employee_compensation ec
                SET "employmentDetailId" = ed.id
                FROM employment_detail ed
                WHERE ec."employmentDetailId" IS NULL
                  AND ed."employeeId" = ec."employeeId"
                  AND ed."startDate" <= ec."effectiveDate"
                  AND (ed."endDate" IS NULL OR ed."endDate" >= ec."effectiveDate")
            ');

            DB::statement('CREATE INDEX IF NOT EXISTS employee_compensation_contract_dates_idx ON employee_compensation ("employmentDetailId", "effectiveDate", "endDate")');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS employee_compensation_active_contract_unique ON employee_compensation ("employmentDetailId") WHERE "isActive" = true AND "employmentDetailId" IS NOT NULL');
        }

        if (Schema::hasTable('scheduled_work') && !Schema::hasColumn('scheduled_work', 'employmentDetailId')) {
            Schema::table('scheduled_work', function (Blueprint $table) {
                $table->foreignUuid('employmentDetailId')
                    ->nullable()
                    ->after('employeeId')
                    ->constrained('employment_detail')
                    ->nullOnDelete();
            });

            DB::statement('CREATE INDEX IF NOT EXISTS scheduled_work_employment_detail_idx ON scheduled_work ("employmentDetailId")');
        }

        if (Schema::hasTable('schedule_employee_timesheet') && !Schema::hasColumn('schedule_employee_timesheet', 'employmentDetailId')) {
            Schema::table('schedule_employee_timesheet', function (Blueprint $table) {
                $table->foreignUuid('employmentDetailId')
                    ->nullable()
                    ->after('employeeId')
                    ->constrained('employment_detail')
                    ->nullOnDelete();
            });

            DB::statement('CREATE INDEX IF NOT EXISTS schedule_employee_timesheet_employment_detail_idx ON schedule_employee_timesheet ("employmentDetailId")');
        }

        if (Schema::hasTable('timesheet') && !Schema::hasColumn('timesheet', 'employmentDetailId')) {
            Schema::table('timesheet', function (Blueprint $table) {
                $table->foreignUuid('employmentDetailId')
                    ->nullable()
                    ->after('employeeId')
                    ->constrained('employment_detail')
                    ->nullOnDelete();
            });

            DB::statement('CREATE INDEX IF NOT EXISTS timesheet_employment_detail_idx ON timesheet ("employmentDetailId")');
        }

        if (Schema::hasTable('timesheet') && !Schema::hasColumn('timesheet', 'employeeCompensationId')) {
            Schema::table('timesheet', function (Blueprint $table) {
                $table->foreignUuid('employeeCompensationId')
                    ->nullable()
                    ->after('employmentDetailId')
                    ->constrained('employee_compensation')
                    ->nullOnDelete();
            });

            DB::statement('CREATE INDEX IF NOT EXISTS timesheet_employee_compensation_idx ON timesheet ("employeeCompensationId")');
        }

        if (Schema::hasTable('payroll') && !Schema::hasColumn('payroll', 'employmentDetailId')) {
            Schema::table('payroll', function (Blueprint $table) {
                $table->foreignUuid('employmentDetailId')
                    ->nullable()
                    ->after('employeeId')
                    ->constrained('employment_detail')
                    ->nullOnDelete();
            });

            DB::statement('CREATE INDEX IF NOT EXISTS payroll_employment_detail_idx ON payroll ("employmentDetailId")');
        }

        if (Schema::hasTable('payroll') && !Schema::hasColumn('payroll', 'employeeCompensationId')) {
            Schema::table('payroll', function (Blueprint $table) {
                $table->foreignUuid('employeeCompensationId')
                    ->nullable()
                    ->after('employmentDetailId')
                    ->constrained('employee_compensation')
                    ->nullOnDelete();
            });

            DB::statement('CREATE INDEX IF NOT EXISTS payroll_employee_compensation_idx ON payroll ("employeeCompensationId")');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll') && Schema::hasColumn('payroll', 'employeeCompensationId')) {
            Schema::table('payroll', function (Blueprint $table) {
                $table->dropForeign(['employeeCompensationId']);
                $table->dropColumn('employeeCompensationId');
            });
        }

        if (Schema::hasTable('payroll') && Schema::hasColumn('payroll', 'employmentDetailId')) {
            Schema::table('payroll', function (Blueprint $table) {
                $table->dropForeign(['employmentDetailId']);
                $table->dropColumn('employmentDetailId');
            });
        }

        if (Schema::hasTable('timesheet') && Schema::hasColumn('timesheet', 'employeeCompensationId')) {
            Schema::table('timesheet', function (Blueprint $table) {
                $table->dropForeign(['employeeCompensationId']);
                $table->dropColumn('employeeCompensationId');
            });
        }

        if (Schema::hasTable('timesheet') && Schema::hasColumn('timesheet', 'employmentDetailId')) {
            Schema::table('timesheet', function (Blueprint $table) {
                $table->dropForeign(['employmentDetailId']);
                $table->dropColumn('employmentDetailId');
            });
        }

        if (Schema::hasTable('schedule_employee_timesheet') && Schema::hasColumn('schedule_employee_timesheet', 'employmentDetailId')) {
            Schema::table('schedule_employee_timesheet', function (Blueprint $table) {
                $table->dropForeign(['employmentDetailId']);
                $table->dropColumn('employmentDetailId');
            });
        }

        if (Schema::hasTable('scheduled_work') && Schema::hasColumn('scheduled_work', 'employmentDetailId')) {
            Schema::table('scheduled_work', function (Blueprint $table) {
                $table->dropForeign(['employmentDetailId']);
                $table->dropColumn('employmentDetailId');
            });
        }

        if (Schema::hasTable('employee_compensation') && Schema::hasColumn('employee_compensation', 'employmentDetailId')) {
            Schema::table('employee_compensation', function (Blueprint $table) {
                $table->dropForeign(['employmentDetailId']);
                $table->dropColumn('employmentDetailId');
            });
        }
    }
};
