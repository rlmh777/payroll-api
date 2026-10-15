<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_default_deduction')) {
            return;
        }

        if (Schema::hasColumn('employee_default_deduction', 'bankId')) {
            DB::statement('ALTER TABLE employee_default_deduction DROP CONSTRAINT IF EXISTS employee_default_deduction_bankid_foreign');
            DB::statement('ALTER TABLE employee_default_deduction DROP CONSTRAINT IF EXISTS employee_default_deduction_bankId_foreign');
            DB::statement('ALTER TABLE employee_default_deduction ALTER COLUMN "bankId" DROP NOT NULL');
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->foreign('bankId')->references('id')->on('bank')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('employee_default_deduction', 'accountNumber')) {
            DB::statement('ALTER TABLE employee_default_deduction ALTER COLUMN "accountNumber" DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_default_deduction')) {
            return;
        }

        if (Schema::hasColumn('employee_default_deduction', 'bankId')) {
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->dropForeign(['bankId']);
            });
            DB::statement('ALTER TABLE employee_default_deduction ALTER COLUMN "bankId" SET NOT NULL');
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->foreign('bankId')->references('id')->on('bank')->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('employee_default_deduction', 'accountNumber')) {
            DB::statement('ALTER TABLE employee_default_deduction ALTER COLUMN "accountNumber" SET NOT NULL');
        }
    }
};
