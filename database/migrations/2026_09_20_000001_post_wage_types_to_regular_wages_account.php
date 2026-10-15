<?php

use App\Models\Account;
use App\Models\PayrollEarningCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const OBSOLETE_CODES = ['6102', '6103', '6107'];

    public function up(): void
    {
        $wagesId = Account::query()->where('code1', '6101')->value('id');
        if (! $wagesId) {
            return;
        }

        $obsoleteIds = Account::query()
            ->whereIn('code1', self::OBSOLETE_CODES)
            ->pluck('id')
            ->all();

        foreach (['OVERTIME', 'HOLIDAY', 'VACATION'] as $code) {
            PayrollEarningCode::query()->where('code', $code)->update(['account_id' => $wagesId]);
        }

        if (! PayrollEarningCode::query()->where('code', 'VACATION')->exists()) {
            PayrollEarningCode::query()->create([
                'code' => 'VACATION',
                'name' => 'Vacation Pay',
                'account_id' => $wagesId,
                'is_taxable' => false,
                'is_ss_subject' => false,
                'is_active' => true,
                'sort_order' => 7,
            ]);
        }

        if (Schema::hasTable('payroll_account_mappings')) {
            DB::table('payroll_account_mappings')
                ->where('code', 'VACATION_PAY')
                ->update([
                    'account_id' => $wagesId,
                    'description' => 'Vacation leave pay posts to Regular Wages. Earning code VACATION keeps the pay type for reports.',
                    'updated_at' => now(),
                ]);
        }

        if ($obsoleteIds === []) {
            return;
        }

        $this->retargetAccountIds($obsoleteIds, (string) $wagesId);
        Account::query()->whereIn('id', $obsoleteIds)->delete();
    }

    public function down(): void
    {
        // Obsolete overtime / holiday / vacation expense accounts are not restored.
    }

    /**
     * @param  list<string>  $fromIds
     */
    private function retargetAccountIds(array $fromIds, string $wagesId): void
    {
        $columns = [
            ['payroll_earning_code', 'account_id'],
            ['payroll_account_mappings', 'account_id'],
            ['payroll_earning_line', 'accountId'],
            ['department', 'accountId'],
            ['employment_detail', 'accountId'],
            ['employee_default_allowance', 'accountId'],
            ['employee_default_deduction', 'accountId'],
            ['historical_employee_allowance', 'accountId'],
            ['historical_employee_allowance', 'account_id'],
            ['historical_employee_deduction', 'accountId'],
            ['historical_employee_deduction', 'account_id'],
            ['loan', 'accountId'],
            ['journal_lines', 'account_id'],
            ['payroll_contributions', 'account_id'],
        ];

        foreach ($columns as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)->whereIn($column, $fromIds)->update([$column => $wagesId]);
        }

        if (! Schema::hasTable('tax_calculator_accounts') || ! Schema::hasColumn('tax_calculator_accounts', 'account_id')) {
            return;
        }

        $wagesAlreadyLinked = DB::table('tax_calculator_accounts')->where('account_id', $wagesId)->exists();
        if ($wagesAlreadyLinked) {
            DB::table('tax_calculator_accounts')->whereIn('account_id', $fromIds)->update(['account_id' => null]);

            return;
        }

        DB::table('tax_calculator_accounts')->whereIn('account_id', $fromIds)->update(['account_id' => $wagesId]);
    }
};
