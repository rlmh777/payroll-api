<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // historical_employee_deduction
        Schema::table('historical_employee_deduction', function (Blueprint $table) {
            if (Schema::hasColumn('historical_employee_deduction', 'employeeId')) {
                $table->renameColumn('employeeId', 'employee_id');
            }
            if (Schema::hasColumn('historical_employee_deduction', 'paymentToId')) {
                $table->renameColumn('paymentToId', 'payment_to_id');
            }
            if (Schema::hasColumn('historical_employee_deduction', 'accountId')) {
                $table->renameColumn('accountId', 'account_id');
            }
            if (Schema::hasColumn('historical_employee_deduction', 'deductionTypeId')) {
                $table->renameColumn('deductionTypeId', 'deduction_type_id');
            }
        });

        // historical_employee_allowance
        Schema::table('historical_employee_allowance', function (Blueprint $table) {
            if (Schema::hasColumn('historical_employee_allowance', 'employeeId')) {
                $table->renameColumn('employeeId', 'employee_id');
            }
            if (Schema::hasColumn('historical_employee_allowance', 'allowanceId')) {
                $table->renameColumn('allowanceId', 'allowance_id');
            }
            if (Schema::hasColumn('historical_employee_allowance', 'accountId')) {
                $table->renameColumn('accountId', 'account_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('historical_employee_deduction', function (Blueprint $table) {
            if (Schema::hasColumn('historical_employee_deduction', 'employee_id')) {
                $table->renameColumn('employee_id', 'employeeId');
            }
            if (Schema::hasColumn('historical_employee_deduction', 'payment_to_id')) {
                $table->renameColumn('payment_to_id', 'paymentToId');
            }
            if (Schema::hasColumn('historical_employee_deduction', 'account_id')) {
                $table->renameColumn('account_id', 'accountId');
            }
            if (Schema::hasColumn('historical_employee_deduction', 'deduction_type_id')) {
                $table->renameColumn('deduction_type_id', 'deductionTypeId');
            }
        });

        Schema::table('historical_employee_allowance', function (Blueprint $table) {
            if (Schema::hasColumn('historical_employee_allowance', 'employee_id')) {
                $table->renameColumn('employee_id', 'employeeId');
            }
            if (Schema::hasColumn('historical_employee_allowance', 'allowance_id')) {
                $table->renameColumn('allowance_id', 'allowanceId');
            }
            if (Schema::hasColumn('historical_employee_allowance', 'account_id')) {
                $table->renameColumn('account_id', 'accountId');
            }
        });
    }
};

