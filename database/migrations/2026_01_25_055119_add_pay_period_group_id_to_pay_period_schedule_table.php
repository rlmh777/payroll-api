<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pay_period_schedule', function (Blueprint $table) {
            $table->foreignUuid('pay_period_group_id')
                ->nullable()
                ->after('pay_date')
                ->constrained('pay_period_groups')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pay_period_schedule', function (Blueprint $table) {
            $table->dropForeign(['pay_period_group_id']);
            $table->dropColumn('pay_period_group_id');
        });
    }
};
