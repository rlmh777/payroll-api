<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('menus')) {
            return;
        }

        foreach (['view-job-title', 'job-title-crud'] as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName]);
        }

        foreach (['super-admin', 'admin', 'hr-admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo(['view-job-title', 'job-title-crud']);
            }
        }

        $generalId = DB::table('menus')
            ->where('title', 'General')
            ->where('type', 'submenu')
            ->orderBy('id')
            ->value('id');

        if (!$generalId) {
            return;
        }

        $existing = DB::table('menus')->where('route', '/settings/job-titles')->first();
        if ($existing) {
            DB::table('menus')->where('id', $existing->id)->update([
                'parent_id' => $generalId,
                'title' => 'Job Titles',
                'icon' => 'work',
                'permission' => 'view-job-title',
                'order' => 16,
                'type' => 'submenu',
                'is_active' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('menus')->insert([
            'parent_id' => $generalId,
            'title' => 'Job Titles',
            'route' => '/settings/job-titles',
            'icon' => 'work',
            'permission' => 'view-job-title',
            'order' => 16,
            'type' => 'submenu',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')->where('route', '/settings/job-titles')->delete();
        }
    }
};
