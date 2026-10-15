<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deduction_type') && ! Schema::hasColumn('deduction_type', 'accountId')) {
            Schema::table('deduction_type', function (Blueprint $table) {
                $table->foreignUuid('accountId')->nullable()->after('defaultAmount')->constrained('accounts')->nullOnDelete();
            });
        }

        if (Schema::hasTable('employee_default_deduction') && Schema::hasColumn('employee_default_deduction', 'accountId')) {
            DB::statement('ALTER TABLE employee_default_deduction DROP CONSTRAINT IF EXISTS employee_default_deduction_accountid_foreign');
            DB::statement('ALTER TABLE employee_default_deduction DROP CONSTRAINT IF EXISTS employee_default_deduction_accountId_foreign');
            DB::statement('ALTER TABLE employee_default_deduction ALTER COLUMN "accountId" DROP NOT NULL');
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->foreign('accountId')->references('id')->on('accounts')->nullOnDelete();
            });
        }

        if (Schema::hasTable('deduction_type') && Schema::hasColumn('deduction_type', 'accountId')) {
            DB::statement('
                UPDATE deduction_type dt
                SET "accountId" = src."accountId"
                FROM (
                    SELECT "deductionTypeId", (array_agg("accountId"))[1] AS "accountId"
                    FROM employee_default_deduction
                    WHERE "accountId" IS NOT NULL
                    GROUP BY "deductionTypeId"
                    HAVING COUNT(DISTINCT "accountId") = 1
                ) src
                WHERE dt.id = src."deductionTypeId"
                  AND dt."accountId" IS NULL
            ');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_default_deduction') && Schema::hasColumn('employee_default_deduction', 'accountId')) {
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->dropForeign(['accountId']);
            });
            DB::statement('ALTER TABLE employee_default_deduction ALTER COLUMN "accountId" SET NOT NULL');
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->foreign('accountId')->references('id')->on('accounts')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('deduction_type') && Schema::hasColumn('deduction_type', 'accountId')) {
            Schema::table('deduction_type', function (Blueprint $table) {
                $table->dropForeign(['accountId']);
                $table->dropColumn('accountId');
            });
        }
    }
};
