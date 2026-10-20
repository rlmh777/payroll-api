<?php

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $view = Permission::firstOrCreate([
            'name' => 'view-banks',
            'guard_name' => 'web',
        ]);
        $crud = Permission::firstOrCreate([
            'name' => 'banks-crud',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->whereHas('permissions', function ($query) {
                $query->whereIn('name', [
                    'view-bank-account-type',
                    'bank-account-type-crud',
                    'view-organization',
                    'view-general',
                ]);
            })
            ->orWhereIn('name', ['admin', 'super-admin', 'superadmin'])
            ->get()
            ->each(function (Role $role) use ($view, $crud) {
                $role->givePermissionTo([$view, $crud]);
                $role->users()->get()->each(
                    fn ($user) => $user->givePermissionTo([$view, $crud]),
                );
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (! Schema::hasTable('menus')) {
            return;
        }

        $generalId = Menu::query()
            ->where('system_key', 'payroll.settings.general')
            ->value('id');

        if (! $generalId) {
            $generalId = Menu::query()
                ->where('title', 'General')
                ->where('type', 'submenu')
                ->where(function ($query) {
                    $query->where('route', '/payroll/settings')
                        ->orWhere('route', '/settings');
                })
                ->value('id');
        }

        if (! $generalId) {
            return;
        }

        Menu::query()->updateOrCreate(
            ['system_key' => 'payroll.settings.banks'],
            [
                'parent_id' => $generalId,
                'title' => 'Banks',
                'route' => '/payroll/settings/banks',
                'icon' => 'fas fa-university',
                'permission' => 'view-banks',
                'order' => 6,
                'is_active' => true,
                'type' => 'submenu',
                'module_code' => 'payroll',
                'source' => 'system',
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->where('system_key', 'payroll.settings.banks')->delete();
            Menu::query()->where('route', '/payroll/settings/banks')->delete();
        }

        Permission::query()->whereIn('name', [
            'view-banks',
            'banks-crud',
        ])->delete();
    }
};
