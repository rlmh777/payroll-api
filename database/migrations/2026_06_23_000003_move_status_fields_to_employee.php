<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee', function (Blueprint $table) {
            $table->foreignId('employmentStatusId')
                ->nullable()
                ->constrained('employment_status')
                ->nullOnDelete();
            $table->foreignId('employeeStatusId')
                ->nullable()
                ->constrained('employee_status')
                ->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE employee e
            SET
                "employmentStatusId" = ed."employmentStatusId",
                "employeeStatusId" = ed."employeeStatusId"
            FROM employment_detail ed
            WHERE ed."employeeId" = e.id
              AND ed."isActive" = true
        SQL);

        Schema::table('employment_detail', function (Blueprint $table) {
            $table->dropForeign(['employmentStatusId']);
            $table->dropForeign(['employeeStatusId']);
            $table->dropColumn(['employmentStatusId', 'employeeStatusId']);
        });
    }

    public function down(): void
    {
        Schema::table('employment_detail', function (Blueprint $table) {
            $table->foreignId('employmentStatusId')
                ->nullable()
                ->constrained('employment_status')
                ->nullOnDelete();
            $table->foreignId('employeeStatusId')
                ->nullable()
                ->constrained('employee_status')
                ->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE employment_detail ed
            SET
                "employmentStatusId" = e."employmentStatusId",
                "employeeStatusId" = e."employeeStatusId"
            FROM employee e
            WHERE ed."employeeId" = e.id
              AND ed."isActive" = true
        SQL);

        Schema::table('employee', function (Blueprint $table) {
            $table->dropForeign(['employmentStatusId']);
            $table->dropForeign(['employeeStatusId']);
            $table->dropColumn(['employmentStatusId', 'employeeStatusId']);
        });
    }
};
