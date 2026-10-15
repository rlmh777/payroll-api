<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->string('code', 64)->primary();
            $table->string('title');
            $table->string('icon')->nullable();
            $table->string('default_route')->nullable();
            $table->boolean('is_core')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('version', 32)->default('1.0.0');
            $table->timestamps();
        });

        Schema::create('company_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->string('module_code', 64);
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->uuid('enabled_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('company')->cascadeOnDelete();
            $table->foreign('module_code')->references('code')->on('modules')->cascadeOnDelete();
            $table->unique(['company_id', 'module_code']);
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->string('module_code', 64)->nullable()->after('type');
            $table->string('source', 32)->default('system')->after('module_code');
            $table->string('system_key', 128)->nullable()->after('source');

            $table->index('module_code');
            $table->unique('system_key');
        });

        $this->seedModules();
        $this->seedCompanyModules();
        $this->backfillMenus();
        $this->seedPermissionsAndMenu();
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropUnique(['system_key']);
            $table->dropIndex(['module_code']);
            $table->dropColumn(['module_code', 'source', 'system_key']);
        });

        Schema::dropIfExists('company_modules');
        Schema::dropIfExists('modules');
    }

    private function seedModules(): void
    {
        $now = now();
        $modules = [
            ['code' => 'core', 'title' => 'Core', 'icon' => 'home', 'default_route' => '/', 'is_core' => true, 'sort_order' => 1],
            ['code' => 'hr', 'title' => 'HR', 'icon' => 'groups', 'default_route' => '/employees', 'is_core' => false, 'sort_order' => 2],
            ['code' => 'payroll', 'title' => 'Payroll', 'icon' => 'payments', 'default_route' => '/payroll/overview', 'is_core' => false, 'sort_order' => 3],
            ['code' => 'admin', 'title' => 'Administration', 'icon' => 'settings', 'default_route' => '/settings', 'is_core' => true, 'sort_order' => 99],
            ['code' => 'performance', 'title' => 'Performance', 'icon' => 'trending_up', 'default_route' => '/performance', 'is_core' => false, 'sort_order' => 4],
            ['code' => 'recruiting', 'title' => 'Recruiting', 'icon' => 'person_search', 'is_core' => false, 'sort_order' => 5],
            ['code' => 'assets', 'title' => 'Assets', 'icon' => 'inventory_2', 'is_core' => false, 'sort_order' => 6],
        ];

        foreach ($modules as $module) {
            DB::table('modules')->insert([
                ...$module,
                'is_active' => true,
                'version' => '1.0.0',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedCompanyModules(): void
    {
        if (! Schema::hasTable('company')) {
            return;
        }

        $companyId = DB::table('company')->orderBy('id')->value('id');
        if (! $companyId) {
            return;
        }

        $now = now();
        $enabledByDefault = ['core', 'hr', 'payroll', 'admin'];
        $modules = DB::table('modules')->pluck('code');

        foreach ($modules as $code) {
            DB::table('company_modules')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'module_code' => $code,
                'enabled' => in_array($code, $enabledByDefault, true),
                'config' => null,
                'enabled_at' => in_array($code, $enabledByDefault, true) ? $now : null,
                'enabled_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function backfillMenus(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        $rootMap = [
            '/' => ['module_code' => 'core', 'system_key' => 'core.dashboard'],
            '/employees' => ['module_code' => 'hr', 'system_key' => 'hr.employees'],
            '/settings' => ['module_code' => 'admin', 'system_key' => 'admin.settings'],
            '/scheduler' => ['module_code' => 'hr', 'system_key' => 'hr.scheduler'],
            '/timesheet' => ['module_code' => 'hr', 'system_key' => 'hr.timesheet'],
            '/leaves' => ['module_code' => 'hr', 'system_key' => 'hr.leaves'],
            '/payroll' => ['module_code' => 'payroll', 'system_key' => 'payroll.root'],
            '/reports' => ['module_code' => 'core', 'system_key' => 'core.reports'],
        ];

        foreach ($rootMap as $route => $meta) {
            DB::table('menus')
                ->whereNull('parent_id')
                ->where('route', $route)
                ->update([
                    'module_code' => $meta['module_code'],
                    'system_key' => $meta['system_key'],
                    'source' => 'system',
                ]);
        }

        $this->assignModuleCodeToDescendants('hr', ['hr.employees', 'hr.scheduler', 'hr.timesheet', 'hr.leaves']);
        $this->assignModuleCodeToDescendants('payroll', ['payroll.root']);
        $this->assignModuleCodeToDescendants('admin', ['admin.settings']);
        $this->assignModuleCodeToDescendants('core', ['core.dashboard', 'core.reports']);

        DB::table('menus')
            ->whereNull('module_code')
            ->orderBy('id')
            ->chunkById(100, function ($menus) {
                foreach ($menus as $menu) {
                    if (! $menu->parent_id) {
                        continue;
                    }

                    $parentModule = DB::table('menus')->where('id', $menu->parent_id)->value('module_code');
                    if ($parentModule) {
                        DB::table('menus')->where('id', $menu->id)->update([
                            'module_code' => $parentModule,
                            'source' => 'system',
                        ]);
                    }
                }
            }, 'id');
    }

    private function assignModuleCodeToDescendants(string $moduleCode, array $rootSystemKeys): void
    {
        $rootIds = DB::table('menus')->whereIn('system_key', $rootSystemKeys)->pluck('id');
        foreach ($rootIds as $rootId) {
            $this->assignModuleToTree($rootId, $moduleCode);
        }
    }

    private function assignModuleToTree(string $menuId, string $moduleCode): void
    {
        DB::table('menus')->where('id', $menuId)->update([
            'module_code' => $moduleCode,
            'source' => 'system',
        ]);

        $childIds = DB::table('menus')->where('parent_id', $menuId)->pluck('id');
        foreach ($childIds as $childId) {
            $this->assignModuleToTree($childId, $moduleCode);
        }
    }

    private function seedPermissionsAndMenu(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::firstOrCreate([
            'name' => 'manage-modules',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->whereIn('name', ['admin', 'super-admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo('manage-modules'));

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        if (! Schema::hasTable('menus')) {
            return;
        }

        $settingsId = DB::table('menus')->where('route', '/settings')->whereNull('parent_id')->value('id');
        if (! $settingsId) {
            return;
        }

        $exists = DB::table('menus')->where('route', '/settings/modules')->exists();
        if ($exists) {
            return;
        }

        $maxOrder = (int) DB::table('menus')->where('parent_id', $settingsId)->max('order');

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $settingsId,
            'title' => 'Modules',
            'route' => '/settings/modules',
            'icon' => 'apps',
            'permission' => 'manage-modules',
            'order' => $maxOrder + 1,
            'is_active' => true,
            'type' => 'submenu',
            'module_code' => 'admin',
            'source' => 'system',
            'system_key' => 'admin.modules',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
