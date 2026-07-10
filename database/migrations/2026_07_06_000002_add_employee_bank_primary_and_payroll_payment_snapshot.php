<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_bank', function (Blueprint $table) {
            $table->boolean('isPrimary')->default(false)->after('accountNumber');
        });

        if (Schema::hasColumn('employee_bank', 'notes')) {
            DB::statement('ALTER TABLE employee_bank ALTER COLUMN notes DROP NOT NULL');
        }

        Schema::table('payroll', function (Blueprint $table) {
            $table->foreignUuid('employeeBankId')
                ->nullable()
                ->after('paymentMethodId')
                ->constrained('employee_bank')
                ->nullOnDelete();
            $table->foreignUuid('bankId')
                ->nullable()
                ->after('employeeBankId')
                ->constrained('bank')
                ->nullOnDelete();
            $table->string('accountNumber')->nullable()->after('bankId');
        });

        // Mark the earliest account per employee as primary when none is set.
        $employeeIds = DB::table('employee_bank')
            ->select('employeeId')
            ->distinct()
            ->pluck('employeeId');

        foreach ($employeeIds as $employeeId) {
            $hasPrimary = DB::table('employee_bank')
                ->where('employeeId', $employeeId)
                ->where('isPrimary', true)
                ->exists();

            if ($hasPrimary) {
                continue;
            }

            $firstId = DB::table('employee_bank')
                ->where('employeeId', $employeeId)
                ->orderBy('created_at')
                ->value('id');

            if ($firstId) {
                DB::table('employee_bank')
                    ->where('id', $firstId)
                    ->update(['isPrimary' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('payroll', function (Blueprint $table) {
            $table->dropForeign(['employeeBankId']);
            $table->dropForeign(['bankId']);
            $table->dropColumn(['employeeBankId', 'bankId', 'accountNumber']);
        });

        Schema::table('employee_bank', function (Blueprint $table) {
            $table->dropColumn('isPrimary');
        });

        if (Schema::hasColumn('employee_bank', 'notes')) {
            DB::statement('ALTER TABLE employee_bank ALTER COLUMN notes SET NOT NULL');
        }
    }
};
