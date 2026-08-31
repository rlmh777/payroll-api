<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')
                ->where('title', 'Allowances')
                ->update(['title' => 'Other Payments']);

            DB::table('menus')
                ->where('title', 'Payroll Allowances')
                ->update(['title' => 'Payroll Other Payments']);
        }

        if (Schema::hasTable('allowance')) {
            DB::table('allowance')
                ->where('name', 'Payroll Import')
                ->update(['note' => 'Created automatically for payroll run imports.']);
        }

        if (Schema::hasTable('payroll_earning_codes')) {
            DB::table('payroll_earning_codes')
                ->where('name', 'Allowances')
                ->update(['name' => 'Other Payments']);
        }

        if (Schema::hasTable('accounts')) {
            DB::table('accounts')
                ->where('name', 'Allowances')
                ->update(['name' => 'Other Payments']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')
                ->where('title', 'Other Payments')
                ->where('route', '/payroll/allowances')
                ->update(['title' => 'Allowances']);
        }

        if (Schema::hasTable('payroll_earning_codes')) {
            DB::table('payroll_earning_codes')
                ->where('name', 'Other Payments')
                ->update(['name' => 'Allowances']);
        }

        if (Schema::hasTable('accounts')) {
            DB::table('accounts')
                ->where('name', 'Other Payments')
                ->update(['name' => 'Allowances']);
        }
    }
};
