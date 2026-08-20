<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_calculator_accounts') && ! Schema::hasColumn('tax_calculator_accounts', 'tax_basis')) {
            Schema::table('tax_calculator_accounts', function (Blueprint $table) {
                $table->string('tax_basis', 16)->default('gross')->after('is_rollup');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tax_calculator_accounts') && Schema::hasColumn('tax_calculator_accounts', 'tax_basis')) {
            Schema::table('tax_calculator_accounts', function (Blueprint $table) {
                $table->dropColumn('tax_basis');
            });
        }
    }
};
