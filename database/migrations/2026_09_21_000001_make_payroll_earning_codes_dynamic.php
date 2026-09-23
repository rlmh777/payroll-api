<?php

use App\Models\PayrollEarningCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_earning_code')) {
            Schema::table('payroll_earning_code', function (Blueprint $table) {
                if (! Schema::hasColumn('payroll_earning_code', 'source')) {
                    $table->string('source', 32)->nullable()->after('account_id');
                }
                if (! Schema::hasColumn('payroll_earning_code', 'post_to_department_account')) {
                    $table->boolean('post_to_department_account')->default(false)->after('source');
                }
            });

            if (! $this->indexExists('payroll_earning_code', 'payroll_earning_code_source_unique')) {
                Schema::table('payroll_earning_code', function (Blueprint $table) {
                    $table->unique('source', 'payroll_earning_code_source_unique');
                });
            }

            $sourceMap = [
                'REGULAR' => [PayrollEarningCode::SOURCE_TIMESHEET_REGULAR, true],
                'OVERTIME' => [PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME, true],
                'HOLIDAY' => [PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY, true],
                'VACATION' => [PayrollEarningCode::SOURCE_VACATION, true],
                'ALLOWANCE' => [PayrollEarningCode::SOURCE_ALLOWANCE_FALLBACK, false],
                'OTHER_PAYMENTS' => [PayrollEarningCode::SOURCE_ALLOWANCE_FALLBACK, false],
            ];

            foreach ($sourceMap as $code => [$source, $postToDepartment]) {
                PayrollEarningCode::query()
                    ->whereRaw('UPPER(code) = ?', [$code])
                    ->whereNull('source')
                    ->update([
                        'source' => $source,
                        'post_to_department_account' => $postToDepartment,
                    ]);
            }
        }

        if (Schema::hasTable('allowance') && ! Schema::hasColumn('allowance', 'payroll_earning_code_id')) {
            Schema::table('allowance', function (Blueprint $table) {
                $table->unsignedBigInteger('payroll_earning_code_id')->nullable()->after('defaultAmount');
                $table->foreign('payroll_earning_code_id')
                    ->references('id')
                    ->on('payroll_earning_code')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('allowance') && Schema::hasColumn('allowance', 'payroll_earning_code_id')) {
            Schema::table('allowance', function (Blueprint $table) {
                $table->dropForeign(['payroll_earning_code_id']);
                $table->dropColumn('payroll_earning_code_id');
            });
        }

        if (Schema::hasTable('payroll_earning_code')) {
            Schema::table('payroll_earning_code', function (Blueprint $table) {
                if ($this->indexExists('payroll_earning_code', 'payroll_earning_code_source_unique')) {
                    $table->dropUnique('payroll_earning_code_source_unique');
                }
                if (Schema::hasColumn('payroll_earning_code', 'post_to_department_account')) {
                    $table->dropColumn('post_to_department_account');
                }
                if (Schema::hasColumn('payroll_earning_code', 'source')) {
                    $table->dropColumn('source');
                }
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?', [$table, $index]);

        return $rows !== [];
    }
};
