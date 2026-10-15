<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pay_period_schedule', 'payrate_frequency_id')) {
            Schema::table('pay_period_schedule', function (Blueprint $table) {
                $table->dropForeign(['payrate_frequency_id']);
                $table->dropColumn('payrate_frequency_id');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('pay_period_schedule', 'payrate_frequency_id')) {
            Schema::table('pay_period_schedule', function (Blueprint $table) {
                $table->foreignId('payrate_frequency_id')
                    ->nullable()
                    ->constrained('payrate_frequency')
                    ->onDelete('set null');
            });
        }
    }
};
