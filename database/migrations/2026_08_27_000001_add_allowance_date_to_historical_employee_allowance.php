<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('historical_employee_allowance')) {
            return;
        }

        Schema::table('historical_employee_allowance', function (Blueprint $table) {
            if (! Schema::hasColumn('historical_employee_allowance', 'allowance_date')) {
                $table->date('allowance_date')->nullable();
            }
        });

        if (Schema::hasColumn('historical_employee_allowance', 'allowance_date')) {
            DB::table('historical_employee_allowance')
                ->whereNull('allowance_date')
                ->update(['allowance_date' => DB::raw('DATE(created_at)')]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('historical_employee_allowance')) {
            return;
        }

        Schema::table('historical_employee_allowance', function (Blueprint $table) {
            if (Schema::hasColumn('historical_employee_allowance', 'allowance_date')) {
                $table->dropColumn('allowance_date');
            }
        });
    }
};
