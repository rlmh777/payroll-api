<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pay_period_schedule', 'payrate_frequency_id')) {
            Schema::table('pay_period_schedule', function (Blueprint $table) {
                $table->foreignId('payrate_frequency_id')
                    ->nullable()
                    ->constrained('payrate_frequency')
                    ->onDelete('set null');
            });
        }

        Schema::table('payroll_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_runs', 'status')) {
                $table->enum('status', ['draft', 'posted'])->default('draft');
            }
        });

        if (!Schema::hasColumn('payroll_runs', 'payrate_frequency_id')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->foreignId('payrate_frequency_id')
                    ->nullable()
                    ->constrained('payrate_frequency')
                    ->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pay_period_schedule', 'payrate_frequency_id')) {
            Schema::table('pay_period_schedule', function (Blueprint $table) {
                $table->dropForeign(['payrate_frequency_id']);
                $table->dropColumn('payrate_frequency_id');
            });
        }

        if (Schema::hasColumn('payroll_runs', 'payrate_frequency_id')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->dropForeign(['payrate_frequency_id']);
                $table->dropColumn('payrate_frequency_id');
            });
        }
    }
};
