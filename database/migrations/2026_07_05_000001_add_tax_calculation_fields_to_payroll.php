<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll', 'applied_personal_relief_id')) {
                $table->uuid('applied_personal_relief_id')->nullable()->after('ss_calculation_detail');
            }

            if (!Schema::hasColumn('payroll', 'tax_calculation_detail')) {
                $table->json('tax_calculation_detail')->nullable()->after('applied_personal_relief_id');
            }
        });

        if (
            Schema::hasColumn('payroll', 'applied_personal_relief_id')
            && Schema::hasTable('personal_relief')
        ) {
            Schema::table('payroll', function (Blueprint $table) {
                $table->foreign('applied_personal_relief_id')
                    ->references('id')
                    ->on('personal_relief')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('payroll', function (Blueprint $table) {
            if (Schema::hasColumn('payroll', 'applied_personal_relief_id')) {
                $table->dropForeign(['applied_personal_relief_id']);
                $table->dropColumn('applied_personal_relief_id');
            }

            if (Schema::hasColumn('payroll', 'tax_calculation_detail')) {
                $table->dropColumn('tax_calculation_detail');
            }
        });
    }
};
