<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_calculator_account_rates') && ! Schema::hasColumn('tax_calculator_account_rates', 'tax_basis')) {
            Schema::table('tax_calculator_account_rates', function (Blueprint $table) {
                $table->string('tax_basis', 16)->default('gross')->after('rate_code');
            });

            DB::table('tax_calculator_account_rates')
                ->join('tax_calculator_accounts', 'tax_calculator_accounts.id', '=', 'tax_calculator_account_rates.tax_calculator_account_id')
                ->where('tax_calculator_accounts.tax_basis', 'net')
                ->update(['tax_calculator_account_rates.tax_basis' => 'net']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tax_calculator_account_rates') && Schema::hasColumn('tax_calculator_account_rates', 'tax_basis')) {
            Schema::table('tax_calculator_account_rates', function (Blueprint $table) {
                $table->dropColumn('tax_basis');
            });
        }
    }
};
