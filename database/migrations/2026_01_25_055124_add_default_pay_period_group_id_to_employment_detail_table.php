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
        Schema::table('employment_detail', function (Blueprint $table) {
            $table->foreignUuid('defaultPayPeriodGroupId')
                ->nullable()
                ->after('worksiteId')
                ->constrained('pay_period_groups')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employment_detail', function (Blueprint $table) {
            $table->dropForeign(['defaultPayPeriodGroupId']);
            $table->dropColumn('defaultPayPeriodGroupId');
        });
    }
};
