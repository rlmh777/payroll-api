<?php

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_page_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->json('layout')->nullable();
            $table->timestamps();
        });

        $view = Permission::firstOrCreate([
            'name' => 'view-login-page',
            'guard_name' => 'web',
        ]);
        $crud = Permission::firstOrCreate([
            'name' => 'login-page-crud',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->whereIn('name', ['admin'])
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

        $adminSettingsId = Menu::query()->where('system_key', 'admin.settings')->value('id');
        if (! $adminSettingsId) {
            return;
        }

        Menu::query()->updateOrCreate(
            ['system_key' => 'admin.login_page'],
            [
                'parent_id' => $adminSettingsId,
                'title' => 'Login Page',
                'route' => '/admin/settings/login-page',
                'icon' => 'view_quilt',
                'permission' => 'view-login-page',
                'order' => 10,
                'is_active' => true,
                'type' => 'submenu',
                'module_code' => 'admin',
                'source' => 'system',
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->where('system_key', 'admin.login_page')->delete();
        }

        Permission::query()->whereIn('name', ['view-login-page', 'login-page-crud'])->delete();
        Schema::dropIfExists('login_page_settings');
    }
};
