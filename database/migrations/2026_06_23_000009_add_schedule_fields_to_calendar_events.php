<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('calendar', function (Blueprint $table) {
            $table->date('startDate')->nullable()->after('calendar_group_id');
            $table->date('endDate')->nullable()->after('startDate');
            $table->time('startTime')->nullable()->after('endDate');
            $table->time('endTime')->nullable()->after('startTime');
            $table->foreignUuid('employeeId')->nullable()->after('endTime')->constrained('employee')->nullOnDelete();
            $table->unsignedBigInteger('departmentId')->nullable()->after('employeeId');
            $table->foreign('departmentId')->references('id')->on('department')->nullOnDelete();
        });

        DB::table('calendar')->update([
            'startDate' => DB::raw('"date"'),
            'endDate' => DB::raw('"date"'),
            'startTime' => '09:00:00',
            'endTime' => '17:00:00',
        ]);

        Schema::table('employee_leave', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_leave', 'departmentId')) {
                $table->unsignedBigInteger('departmentId')->nullable()->after('employeeId');
                $table->foreign('departmentId')->references('id')->on('department')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_leave', function (Blueprint $table) {
            if (Schema::hasColumn('employee_leave', 'departmentId')) {
                $table->dropForeign(['departmentId']);
                $table->dropColumn('departmentId');
            }
        });

        Schema::table('calendar', function (Blueprint $table) {
            $table->dropForeign(['employeeId']);
            $table->dropForeign(['departmentId']);
            $table->dropColumn(['startDate', 'endDate', 'startTime', 'endTime', 'employeeId', 'departmentId']);
        });
    }
};
