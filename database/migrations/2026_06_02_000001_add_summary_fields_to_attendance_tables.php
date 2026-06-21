<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clocking_log', function (Blueprint $table) {
            $table->string('punchType', 16)->nullable()->after('punchDateTime');
            $table->index('punchType', 'clocking_log_punch_type_idx');
        });

        Schema::table('timesheet', function (Blueprint $table) {
            $table->string('clockInDeviceId')->nullable()->after('clockInTime');
            $table->string('clockOutDeviceId')->nullable()->after('clockOutTime');
            $table->decimal('regularHours', 8, 2)->default(0)->after('hoursWorked');
            $table->decimal('holidayHours', 8, 2)->default(0)->after('overtimeHours');
            $table->decimal('unpaidHours', 8, 2)->default(0)->after('holidayHours');
            $table->string('workingStatus', 32)->default('REGULAR')->after('unpaidHours');
            $table->foreignId('departmentId')
                ->nullable()
                ->after('workingStatus')
                ->constrained('department')
                ->nullOnDelete();
            $table->foreignId('worksiteId')
                ->nullable()
                ->after('departmentId')
                ->constrained('worksite')
                ->nullOnDelete();
            $table->string('payType', 32)->nullable()->after('worksiteId');
            $table->decimal('hourlyRate', 12, 2)->nullable()->after('payType');
            $table->decimal('baseSalary', 12, 2)->nullable()->after('hourlyRate');

            $table->index('workingStatus', 'timesheet_working_status_idx');
            $table->index('departmentId', 'timesheet_department_idx');
            $table->index('worksiteId', 'timesheet_worksite_idx');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            $table->dropIndex('timesheet_working_status_idx');
            $table->dropIndex('timesheet_department_idx');
            $table->dropIndex('timesheet_worksite_idx');
            $table->dropForeign(['departmentId']);
            $table->dropForeign(['worksiteId']);
            $table->dropColumn([
                'clockInDeviceId',
                'clockOutDeviceId',
                'regularHours',
                'holidayHours',
                'unpaidHours',
                'workingStatus',
                'departmentId',
                'worksiteId',
                'payType',
                'hourlyRate',
                'baseSalary',
            ]);
        });

        Schema::table('clocking_log', function (Blueprint $table) {
            $table->dropIndex('clocking_log_punch_type_idx');
            $table->dropColumn('punchType');
        });
    }
};
