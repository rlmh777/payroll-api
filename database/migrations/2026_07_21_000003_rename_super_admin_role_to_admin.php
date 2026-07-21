<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $superAdmin = DB::table('roles')->where('name', 'super-admin')->first();
        $admin = DB::table('roles')->where('name', 'admin')->first();

        if ($superAdmin && $admin) {
            $this->mergeRoleInto($superAdmin->id, $admin->id);
            DB::table('roles')->where('id', $superAdmin->id)->delete();
        } elseif ($superAdmin && ! $admin) {
            DB::table('roles')
                ->where('id', $superAdmin->id)
                ->update([
                    'name' => 'admin',
                    'updated_at' => now(),
                ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $admin = DB::table('roles')->where('name', 'admin')->first();
        if (! $admin) {
            return;
        }

        $superAdminExists = DB::table('roles')->where('name', 'super-admin')->exists();
        if ($superAdminExists) {
            return;
        }

        DB::table('roles')
            ->where('id', $admin->id)
            ->update([
                'name' => 'super-admin',
                'updated_at' => now(),
            ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function mergeRoleInto(string|int $fromRoleId, string|int $toRoleId): void
    {
        if (Schema::hasTable('model_has_roles')) {
            $rows = DB::table('model_has_roles')->where('role_id', $fromRoleId)->get();
            foreach ($rows as $row) {
                $exists = DB::table('model_has_roles')
                    ->where('role_id', $toRoleId)
                    ->where('model_type', $row->model_type)
                    ->where('model_id', $row->model_id)
                    ->exists();

                if ($exists) {
                    DB::table('model_has_roles')
                        ->where('role_id', $fromRoleId)
                        ->where('model_type', $row->model_type)
                        ->where('model_id', $row->model_id)
                        ->delete();
                } else {
                    DB::table('model_has_roles')
                        ->where('role_id', $fromRoleId)
                        ->where('model_type', $row->model_type)
                        ->where('model_id', $row->model_id)
                        ->update(['role_id' => $toRoleId]);
                }
            }
        }

        if (Schema::hasTable('role_has_permissions')) {
            $permissionIds = DB::table('role_has_permissions')
                ->where('role_id', $fromRoleId)
                ->pluck('permission_id');

            foreach ($permissionIds as $permissionId) {
                $exists = DB::table('role_has_permissions')
                    ->where('role_id', $toRoleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $exists) {
                    DB::table('role_has_permissions')->insert([
                        'permission_id' => $permissionId,
                        'role_id' => $toRoleId,
                    ]);
                }
            }

            DB::table('role_has_permissions')->where('role_id', $fromRoleId)->delete();
        }

        if (Schema::hasTable('user_roles')) {
            $rows = DB::table('user_roles')->where('role_id', $fromRoleId)->get();
            foreach ($rows as $row) {
                $exists = DB::table('user_roles')
                    ->where('role_id', $toRoleId)
                    ->where('user_id', $row->user_id)
                    ->exists();

                if ($exists) {
                    DB::table('user_roles')
                        ->where('id', $row->id)
                        ->delete();
                } else {
                    DB::table('user_roles')
                        ->where('id', $row->id)
                        ->update([
                            'role_id' => $toRoleId,
                            'updated_at' => now(),
                        ]);
                }
            }
        }
    }
};
