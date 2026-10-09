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
                ->where('system_key', 'like', 'hr.employees%')
                ->update(['module_code' => 'hr']);
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('code', 'hr')->update([
                'default_route' => '/hr/employees',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')
                ->where('system_key', 'like', 'hr.employees%')
                ->update(['module_code' => 'employee']);
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('code', 'hr')->update([
                'default_route' => '/hr/vacancies',
                'updated_at' => now(),
            ]);
        }
    }
};
