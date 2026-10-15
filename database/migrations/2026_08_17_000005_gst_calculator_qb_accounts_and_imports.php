<?php

use App\Models\Account;
use App\Models\TaxCalculatorAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_calculator_accounts')) {
            Schema::table('tax_calculator_accounts', function (Blueprint $table) {
                if (! Schema::hasColumn('tax_calculator_accounts', 'qb_code')) {
                    $table->string('qb_code', 64)->nullable()->after('account_id');
                }
                if (! Schema::hasColumn('tax_calculator_accounts', 'qb_name')) {
                    $table->string('qb_name', 255)->nullable()->after('qb_code');
                }
                if (! Schema::hasColumn('tax_calculator_accounts', 'is_rollup')) {
                    $table->boolean('is_rollup')->default(false)->after('qb_name');
                }
            });

            $this->dropAccountIdRequired();

            TaxCalculatorAccount::query()
                ->whereNull('qb_code')
                ->whereNotNull('account_id')
                ->each(function (TaxCalculatorAccount $mapping) {
                    $account = Account::query()->find($mapping->account_id);
                    if (! $account) {
                        return;
                    }
                    $mapping->qb_code = preg_replace('/\s+/', '', (string) ($account->code1 ?? $account->id));
                    $mapping->qb_name = $account->name;
                    $mapping->save();
                });

        }

        if (Schema::hasTable('tax_calculator_runs')) {
            Schema::table('tax_calculator_runs', function (Blueprint $table) {
                if (! Schema::hasColumn('tax_calculator_runs', 'import_filename')) {
                    $table->string('import_filename', 255)->nullable()->after('net_of_2251');
                }
                if (! Schema::hasColumn('tax_calculator_runs', 'imported_at')) {
                    $table->timestamp('imported_at')->nullable()->after('import_filename');
                }
                if (! Schema::hasColumn('tax_calculator_runs', 'import_accounts_sheet')) {
                    $table->json('import_accounts_sheet')->nullable()->after('imported_at');
                }
                if (! Schema::hasColumn('tax_calculator_runs', 'import_gst_sheet')) {
                    $table->json('import_gst_sheet')->nullable()->after('import_accounts_sheet');
                }
            });
        }

        if (Schema::hasTable('tax_calculator_run_lines') && ! Schema::hasColumn('tax_calculator_run_lines', 'is_rollup')) {
            Schema::table('tax_calculator_run_lines', function (Blueprint $table) {
                $table->boolean('is_rollup')->default(false)->after('include_btb');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tax_calculator_run_lines') && Schema::hasColumn('tax_calculator_run_lines', 'is_rollup')) {
            Schema::table('tax_calculator_run_lines', function (Blueprint $table) {
                $table->dropColumn('is_rollup');
            });
        }

        if (Schema::hasTable('tax_calculator_runs')) {
            Schema::table('tax_calculator_runs', function (Blueprint $table) {
                foreach (['import_filename', 'imported_at', 'import_accounts_sheet', 'import_gst_sheet'] as $column) {
                    if (Schema::hasColumn('tax_calculator_runs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function dropAccountIdRequired(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        try {
            Schema::table('tax_calculator_accounts', function (Blueprint $table) {
                $table->dropForeign(['account_id']);
            });
        } catch (\Throwable) {
            // Already dropped or named differently.
        }

        try {
            Schema::table('tax_calculator_accounts', function (Blueprint $table) {
                $table->dropUnique(['account_id']);
            });
        } catch (\Throwable) {
            // Ignore.
        }

        if (in_array($driver, ['pgsql', 'postgres'], true)) {
            DB::statement('ALTER TABLE tax_calculator_accounts ALTER COLUMN account_id DROP NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE tax_calculator_accounts MODIFY account_id CHAR(36) NULL');
        }

        try {
            Schema::table('tax_calculator_accounts', function (Blueprint $table) {
                $table->foreign('account_id')
                    ->references('id')
                    ->on('accounts')
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
            // Ignore.
        }
    }
};
