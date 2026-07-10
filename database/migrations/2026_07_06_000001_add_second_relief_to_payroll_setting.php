<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_setting', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_setting', 'secondReliefAmount')) {
                $table->decimal('secondReliefAmount', 14, 2)->default(100)->after('incomeTaxRate');
            }
        });

        if (Schema::hasColumn('payroll_setting', 'secondReliefAmount')) {
            DB::table('payroll_setting')
                ->whereNull('secondReliefAmount')
                ->update(['secondReliefAmount' => 100]);
        }
    }

    public function down(): void
    {
        Schema::table('payroll_setting', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_setting', 'secondReliefAmount')) {
                $table->dropColumn('secondReliefAmount');
            }
        });
    }
};
