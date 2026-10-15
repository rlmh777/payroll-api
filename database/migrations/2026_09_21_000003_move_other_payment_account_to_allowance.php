<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('allowance') && ! Schema::hasColumn('allowance', 'accountId')) {
            Schema::table('allowance', function (Blueprint $table) {
                $table->foreignUuid('accountId')->nullable()->after('defaultAmount')->constrained('accounts')->nullOnDelete();
            });
        }

        if (Schema::hasTable('employee_default_allowance') && Schema::hasColumn('employee_default_allowance', 'accountId')) {
            DB::statement('ALTER TABLE employee_default_allowance DROP CONSTRAINT IF EXISTS employee_default_allowance_accountid_foreign');
            DB::statement('ALTER TABLE employee_default_allowance DROP CONSTRAINT IF EXISTS employee_default_allowance_accountId_foreign');
            DB::statement('ALTER TABLE employee_default_allowance ALTER COLUMN "accountId" DROP NOT NULL');
            Schema::table('employee_default_allowance', function (Blueprint $table) {
                $table->foreign('accountId')->references('id')->on('accounts')->nullOnDelete();
            });
        }

        if (Schema::hasTable('allowance') && Schema::hasColumn('allowance', 'accountId')) {
            DB::statement('
                UPDATE allowance a
                SET "accountId" = src."accountId"
                FROM (
                    SELECT "allowanceId", (array_agg("accountId"))[1] AS "accountId"
                    FROM employee_default_allowance
                    WHERE "accountId" IS NOT NULL
                    GROUP BY "allowanceId"
                    HAVING COUNT(DISTINCT "accountId") = 1
                ) src
                WHERE a.id = src."allowanceId"
                  AND a."accountId" IS NULL
            ');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_default_allowance') && Schema::hasColumn('employee_default_allowance', 'accountId')) {
            Schema::table('employee_default_allowance', function (Blueprint $table) {
                $table->dropForeign(['accountId']);
            });
            DB::statement('ALTER TABLE employee_default_allowance ALTER COLUMN "accountId" SET NOT NULL');
            Schema::table('employee_default_allowance', function (Blueprint $table) {
                $table->foreign('accountId')->references('id')->on('accounts')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('allowance') && Schema::hasColumn('allowance', 'accountId')) {
            Schema::table('allowance', function (Blueprint $table) {
                $table->dropForeign(['accountId']);
                $table->dropColumn('accountId');
            });
        }
    }
};
