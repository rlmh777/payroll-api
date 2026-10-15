<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_calculator_accounts') && ! Schema::hasColumn('tax_calculator_accounts', 'line_kind')) {
            Schema::table('tax_calculator_accounts', function (Blueprint $table) {
                $table->string('line_kind', 16)->nullable()->after('is_rollup');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tax_calculator_accounts') && Schema::hasColumn('tax_calculator_accounts', 'line_kind')) {
            Schema::table('tax_calculator_accounts', function (Blueprint $table) {
                $table->dropColumn('line_kind');
            });
        }
    }
};
