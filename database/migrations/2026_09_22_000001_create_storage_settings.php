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
        Schema::create('storage_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('driver', 20)->default('local');
            $table->string('container', 255)->nullable();
            $table->string('account_name', 255)->nullable();
            $table->text('account_key')->nullable();
            $table->string('endpoint', 500)->nullable();
            $table->string('region', 64)->nullable();
            $table->boolean('use_path_style_endpoint')->default(false);
            $table->string('prefix', 255)->nullable();
            $table->timestamps();
        });

        $view = Permission::firstOrCreate([
            'name' => 'view-file-storage',
            'guard_name' => 'web',
        ]);
        $crud = Permission::firstOrCreate([
            'name' => 'file-storage-crud',
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
            ['system_key' => 'admin.file_storage'],
            [
                'parent_id' => $adminSettingsId,
                'title' => 'File Storage',
                'route' => '/admin/settings/file-storage',
                'icon' => 'cloud',
                'permission' => 'view-file-storage',
                'order' => 8,
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
            Menu::query()->where('system_key', 'admin.file_storage')->delete();
        }

        Permission::query()->whereIn('name', ['view-file-storage', 'file-storage-crud'])->delete();
        Schema::dropIfExists('storage_settings');
    }
};
