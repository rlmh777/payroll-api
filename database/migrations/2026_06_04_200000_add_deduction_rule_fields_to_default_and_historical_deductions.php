<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_default_deduction', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_default_deduction', 'allowPartialDeduction')) {
                $table->boolean('allowPartialDeduction')->default(false);
            }
            if (!Schema::hasColumn('employee_default_deduction', 'applicationRule')) {
                $table->string('applicationRule', 255)->nullable();
            }
            if (!Schema::hasColumn('employee_default_deduction', 'priority')) {
                $table->integer('priority')->default(0);
            }
        });

        Schema::table('historical_employee_deduction', function (Blueprint $table) {
            if (!Schema::hasColumn('historical_employee_deduction', 'carryForwardShortfall')) {
                $table->decimal('carryForwardShortfall', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('historical_employee_deduction', 'priority')) {
                $table->integer('priority')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_default_deduction', function (Blueprint $table) {
            $columns = ['allowPartialDeduction', 'applicationRule', 'priority'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('employee_default_deduction', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('historical_employee_deduction', function (Blueprint $table) {
            $columns = ['carryForwardShortfall', 'priority'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('historical_employee_deduction', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
