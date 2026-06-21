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
        Schema::table('employee_leave', function (Blueprint $table) {
            $table->string('approvalStatus', 32)->default('pending')->after('multiplier');
            $table->date('approvalDate')->nullable()->after('approvalStatus');
            $table->foreignUuid('approverId')->nullable()->after('approvalDate')->constrained('employee')->nullOnDelete();
        });

        Schema::table('schedule_employee_timesheet', function (Blueprint $table) {
            $table->string('approvalStatus', 32)->default('pending')->after('endTime');
            $table->date('approvalDate')->nullable()->after('approvalStatus');
            $table->foreignUuid('approverId')->nullable()->after('approvalDate')->constrained('employee')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_leave', function (Blueprint $table) {
            $table->dropForeign(['approverId']);
            $table->dropColumn(['approverId', 'approvalDate', 'approvalStatus']);
        });

        Schema::table('schedule_employee_timesheet', function (Blueprint $table) {
            $table->dropForeign(['approverId']);
            $table->dropColumn(['approverId', 'approvalDate', 'approvalStatus']);
        });
    }
};
