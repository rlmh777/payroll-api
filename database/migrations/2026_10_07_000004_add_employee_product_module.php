<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('modules') && ! DB::table('modules')->where('code', 'employee')->exists()) {
            DB::table('modules')->insert([
                'code' => 'employee',
                'title' => 'Employee',
                'icon' => 'person',
                'default_route' => '/hr/employees',
                'is_core' => false,
                'is_active' => true,
                'sort_order' => 2,
                'version' => '1.0.0',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('code', 'hr')->update([
                'default_route' => '/hr/vacancies',
                'sort_order' => 3,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('company') && Schema::hasTable('company_modules')) {
            $companyIds = DB::table('company')->orderBy('id')->pluck('id');

            foreach ($companyIds as $companyId) {
                $already = DB::table('company_modules')
                    ->where('company_id', $companyId)
                    ->where('module_code', 'employee')
                    ->exists();

                if ($already) {
                    continue;
                }

                DB::table('company_modules')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'module_code' => 'employee',
                    'enabled' => true,
                    'config' => null,
                    'enabled_at' => $now,
                    'enabled_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('menus')) {
            DB::table('menus')
                ->where('system_key', 'like', 'hr.employees%')
                ->update(['module_code' => 'employee']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')
                ->where('system_key', 'like', 'hr.employees%')
                ->update(['module_code' => 'hr']);
        }

        if (Schema::hasTable('company_modules')) {
            DB::table('company_modules')->where('module_code', 'employee')->delete();
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('code', 'hr')->update([
                'default_route' => '/hr/employees',
                'sort_order' => 2,
                'updated_at' => now(),
            ]);
            DB::table('modules')->where('code', 'employee')->delete();
        }
    }
};
