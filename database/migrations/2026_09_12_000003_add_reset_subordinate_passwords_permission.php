<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const PERMISSION = 'reset-subordinate-passwords';

    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
        );

        foreach (['admin', 'supervisor', 'hr'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        $permission = Permission::query()
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->first();

        if (! $permission) {
            return;
        }

        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            if ($role->hasPermissionTo($permission)) {
                $role->revokePermissionTo($permission);
            }
        }

        $permission->delete();
    }
};
