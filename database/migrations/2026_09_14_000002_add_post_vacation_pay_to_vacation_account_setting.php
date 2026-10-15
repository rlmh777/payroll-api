<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_setting')
            && ! Schema::hasColumn('payroll_setting', 'postVacationPayToVacationAccount')
        ) {
            Schema::table('payroll_setting', function (Blueprint $table) {
                $table->boolean('postVacationPayToVacationAccount')->default(true)->after('secondReliefAmount');
            });
        }

        if (Schema::hasTable('payroll_account_mappings')) {
            DB::table('payroll_account_mappings')
                ->where('code', 'VACATION_PAY')
                ->update([
                    'description' => 'Expense account for vacation leave pay (instead of department wages). Change the linked account here.',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_setting')
            && Schema::hasColumn('payroll_setting', 'postVacationPayToVacationAccount')
        ) {
            Schema::table('payroll_setting', function (Blueprint $table) {
                $table->dropColumn('postVacationPayToVacationAccount');
            });
        }
    }
};
