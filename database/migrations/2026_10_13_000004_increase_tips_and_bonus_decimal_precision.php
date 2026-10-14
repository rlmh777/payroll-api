<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->changeNumeric('payroll', 'tipsAmount', 'numeric(20,10)', '0');
        $this->changeNumeric('payroll', 'bonusAmount', 'numeric(20,10)', '0');
        $this->changeNumeric('payroll_run_pool_total', 'total_amount', 'numeric(20,10)', '0');
        $this->changeNumeric('payroll_run_pool_distribution', 'amount', 'numeric(20,10)', '0');
        $this->changeNumeric('payroll_run_pool_distribution', 'department_amount', 'numeric(20,10)');
    }

    public function down(): void
    {
        $this->changeNumeric('payroll', 'tipsAmount', 'numeric(14,2)', '0');
        $this->changeNumeric('payroll', 'bonusAmount', 'numeric(14,2)', '0');
        $this->changeNumeric('payroll_run_pool_total', 'total_amount', 'numeric(14,2)', '0');
        $this->changeNumeric('payroll_run_pool_distribution', 'amount', 'numeric(14,2)', '0');
        $this->changeNumeric('payroll_run_pool_distribution', 'department_amount', 'numeric(14,2)');
    }

    private function changeNumeric(string $table, string $column, string $type, ?string $default = null): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $quotedTable = $this->quoteIdent($table);
        $quotedColumn = $this->quoteIdent($column);
        $sql = "ALTER TABLE {$quotedTable} ALTER COLUMN {$quotedColumn} TYPE {$type}";
        if ($default !== null) {
            $sql .= " USING COALESCE({$quotedColumn}, {$default})::{$type}";
        }

        DB::statement($sql);
    }

    private function quoteIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
};
