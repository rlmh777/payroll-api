<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_pool_distribution', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_run_pool_distribution', 'paid_shares')) {
                $table->decimal('paid_shares', 14, 4)->nullable();
            }
            if (! Schema::hasColumn('payroll_run_pool_distribution', 'paid_percent')) {
                $table->decimal('paid_percent', 14, 4)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_pool_distribution', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_run_pool_distribution', 'paid_shares')) {
                $table->dropColumn('paid_shares');
            }
            if (Schema::hasColumn('payroll_run_pool_distribution', 'paid_percent')) {
                $table->dropColumn('paid_percent');
            }
        });
    }
};
