<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employee_default_deduction', 'bankId')) {
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->foreignUuid('bankId')->nullable()->constrained('bank')->onDelete('cascade');
            });
        }

        if (!Schema::hasColumn('employee_default_deduction', 'accountNumber')) {
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->string('accountNumber')->nullable();
            });
        }

        if (Schema::hasColumn('employee_default_deduction', 'paymentToId')) {
            DB::statement('
                UPDATE employee_default_deduction AS ed
                SET "bankId" = v."bankId",
                    "accountNumber" = v."accountNumber"
                FROM vendor AS v
                WHERE v.id = ed."paymentToId"
                  AND ed."bankId" IS NULL
            ');

            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->dropForeign(['paymentToId']);
                $table->dropColumn('paymentToId');
            });
        }

        if (Schema::hasColumn('employee_default_deduction', 'bankId')) {
            DB::statement('ALTER TABLE employee_default_deduction ALTER COLUMN "bankId" SET NOT NULL');
        }

        if (Schema::hasColumn('employee_default_deduction', 'accountNumber')) {
            DB::statement('ALTER TABLE employee_default_deduction ALTER COLUMN "accountNumber" SET NOT NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('employee_default_deduction', 'paymentToId')) {
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->foreignUuid('paymentToId')->nullable()->constrained('vendor')->onDelete('cascade');
            });
        }

        if (Schema::hasColumn('employee_default_deduction', 'bankId')) {
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->dropForeign(['bankId']);
                $table->dropColumn('bankId');
            });
        }

        if (Schema::hasColumn('employee_default_deduction', 'accountNumber')) {
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->dropColumn('accountNumber');
            });
        }
    }
};
