<?php

use App\Models\TaxCalculatorAccount;
use App\Models\TaxCalculatorRate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_calculator_accounts') && ! Schema::hasColumn('tax_calculator_accounts', 'parent_id')) {
            Schema::table('tax_calculator_accounts', function (Blueprint $table) {
                $table->uuid('parent_id')->nullable()->after('account_id');
                $table->foreign('parent_id')
                    ->references('id')
                    ->on('tax_calculator_accounts')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('tax_calculator_account_rates')) {
            Schema::create('tax_calculator_account_rates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tax_calculator_account_id');
                $table->string('rate_code', 64);
                $table->timestamps();

                $table->foreign('tax_calculator_account_id')
                    ->references('id')
                    ->on('tax_calculator_accounts')
                    ->cascadeOnDelete();

                $table->unique(['tax_calculator_account_id', 'rate_code'], 'tax_calc_account_rates_unique');
            });
        }

        $this->migrateLegacySingleRateColumns();
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_calculator_account_rates');

        if (Schema::hasTable('tax_calculator_accounts') && Schema::hasColumn('tax_calculator_accounts', 'parent_id')) {
            Schema::table('tax_calculator_accounts', function (Blueprint $table) {
                $table->dropForeign(['parent_id']);
                $table->dropColumn('parent_id');
            });
        }
    }

    private function migrateLegacySingleRateColumns(): void
    {
        if (! Schema::hasTable('tax_calculator_account_rates')) {
            return;
        }

        $validCodes = TaxCalculatorRate::query()->pluck('code')->all();
        $validLookup = array_fill_keys($validCodes, true);

        TaxCalculatorAccount::query()->each(function (TaxCalculatorAccount $account) use ($validLookup) {
            foreach (['business_tax_code', 'gst_code'] as $column) {
                $code = $account->{$column};
                if ($code === null || $code === '' || ! isset($validLookup[$code])) {
                    continue;
                }

                $account->rates()->firstOrCreate(['rate_code' => $code]);
            }
        });
    }
};
