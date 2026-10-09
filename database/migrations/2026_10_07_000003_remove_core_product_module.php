<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')->where('system_key', 'core.dashboard')->update([
                'system_key' => 'payroll.dashboard',
                'module_code' => 'payroll',
                'route' => '/',
            ]);

            DB::table('menus')->where('system_key', 'core.reports')->update([
                'system_key' => 'payroll.reports',
                'module_code' => 'payroll',
            ]);

            DB::table('menus')->where('module_code', 'core')->update([
                'module_code' => 'payroll',
            ]);
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('code', 'payroll')->update([
                'default_route' => '/',
                'sort_order' => 1,
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'preferences')) {
            $users = DB::table('users')->whereNotNull('preferences')->get(['id', 'preferences']);

            foreach ($users as $user) {
                $preferences = $user->preferences;
                if (is_string($preferences)) {
                    $preferences = json_decode($preferences, true);
                }

                if (! is_array($preferences) || ($preferences['default_module'] ?? null) !== 'core') {
                    continue;
                }

                $preferences['default_module'] = 'payroll';

                DB::table('users')->where('id', $user->id)->update([
                    'preferences' => json_encode($preferences),
                ]);
            }
        }

        if (Schema::hasTable('company_modules')) {
            DB::table('company_modules')->where('module_code', 'core')->delete();
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('code', 'core')->delete();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $now = now();

        if (! DB::table('modules')->where('code', 'core')->exists()) {
            DB::table('modules')->insert([
                'code' => 'core',
                'title' => 'Core',
                'icon' => 'home',
                'default_route' => '/',
                'is_core' => false,
                'is_active' => true,
                'sort_order' => 1,
                'version' => '1.0.0',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('company') && Schema::hasTable('company_modules')) {
            $companyId = DB::table('company')->orderBy('id')->value('id');
            if ($companyId && ! DB::table('company_modules')->where('module_code', 'core')->exists()) {
                DB::table('company_modules')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'module_code' => 'core',
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
            DB::table('menus')->where('system_key', 'payroll.dashboard')->update([
                'system_key' => 'core.dashboard',
            ]);
            DB::table('menus')->where('system_key', 'payroll.reports')->update([
                'system_key' => 'core.reports',
            ]);
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('code', 'payroll')->update([
                'default_route' => '/payroll/overview',
                'sort_order' => 3,
                'updated_at' => $now,
            ]);
        }
    }
};
