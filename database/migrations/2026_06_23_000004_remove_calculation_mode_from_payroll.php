<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payroll', 'taxCalculationModeId')) {
            Schema::table('payroll', function (Blueprint $table) {
                $table->dropForeign(['taxCalculationModeId']);
                $table->dropColumn('taxCalculationModeId');
            });
        }

        if (Schema::hasColumn('payroll', 'socialSecurityCalculationModeId')) {
            Schema::table('payroll', function (Blueprint $table) {
                $table->dropForeign(['socialSecurityCalculationModeId']);
                $table->dropColumn('socialSecurityCalculationModeId');
            });
        }

        Schema::dropIfExists('calculation_mode');
    }

    public function down(): void
    {
        if (!Schema::hasTable('calculation_mode')) {
            Schema::create('calculation_mode', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('payroll', 'taxCalculationModeId')) {
            Schema::table('payroll', function (Blueprint $table) {
                $table->foreignId('taxCalculationModeId')
                    ->nullable()
                    ->constrained('calculation_mode')
                    ->onDelete('cascade');
            });
        }

        if (!Schema::hasColumn('payroll', 'socialSecurityCalculationModeId')) {
            Schema::table('payroll', function (Blueprint $table) {
                $table->foreignId('socialSecurityCalculationModeId')
                    ->nullable()
                    ->constrained('calculation_mode')
                    ->onDelete('cascade');
            });
        }
    }
};
