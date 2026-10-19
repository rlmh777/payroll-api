<?php

use App\Models\Account;
use App\Models\PayrollEarningCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_earning_code')) {
            return;
        }

        Schema::table('payroll_earning_code', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_earning_code', 'pay_multiplier')) {
                $table->decimal('pay_multiplier', 5, 2)->nullable()->after('source');
            }
        });

        if ($this->indexExists('payroll_earning_code', 'payroll_earning_code_source_unique')) {
            Schema::table('payroll_earning_code', function (Blueprint $table) {
                $table->dropUnique('payroll_earning_code_source_unique');
            });
        }

        DB::statement('
            CREATE UNIQUE INDEX IF NOT EXISTS payroll_earning_code_source_multiplier_unique
            ON payroll_earning_code (source, (COALESCE(pay_multiplier, 0)))
            WHERE source IS NOT NULL
        ');

        PayrollEarningCode::query()
            ->where('source', PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY)
            ->whereNull('pay_multiplier')
            ->get()
            ->each(function (PayrollEarningCode $code): void {
                $code->pay_multiplier = 1.5;
                if (trim((string) $code->name) === 'Holiday Pay') {
                    $code->name = 'Holiday Pay (1.5 time)';
                }
                $code->save();
            });

        $holiday = PayrollEarningCode::query()
            ->where('source', PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY)
            ->orderBy('sort_order')
            ->first();

        $existsDoubleTime = PayrollEarningCode::query()
            ->where('source', PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY)
            ->where('pay_multiplier', 2)
            ->exists();

        if (! $existsDoubleTime) {
            PayrollEarningCode::query()->create([
                'code' => 'HOLIDAY_DT',
                'name' => 'Holiday Pay (double time)',
                'account_id' => $holiday?->account_id ?? Account::query()->where('code1', '6101')->value('id'),
                'source' => PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
                'pay_multiplier' => 2.0,
                'post_to_department_account' => (bool) ($holiday?->post_to_department_account ?? true),
                'is_taxable' => (bool) ($holiday?->is_taxable ?? true),
                'is_ss_subject' => (bool) ($holiday?->is_ss_subject ?? true),
                'is_active' => true,
                'sort_order' => (int) ($holiday?->sort_order ?? 3) + 1,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_earning_code')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS payroll_earning_code_source_multiplier_unique');

        PayrollEarningCode::query()->where('code', 'HOLIDAY_DT')->delete();

        Schema::table('payroll_earning_code', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_earning_code', 'pay_multiplier')) {
                $table->dropColumn('pay_multiplier');
            }
            if (! $this->indexExists('payroll_earning_code', 'payroll_earning_code_source_unique')) {
                $table->unique('source', 'payroll_earning_code_source_unique');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?', [$table, $index]);

        return $rows !== [];
    }
};
