<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_pool_share', function (Blueprint $table) {
            $table->decimal('payout_amount', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_pool_share', function (Blueprint $table) {
            $table->dropColumn('payout_amount');
        });
    }
};
