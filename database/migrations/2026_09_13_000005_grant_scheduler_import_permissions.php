<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        foreach (['view-calendars', 'calendar-crud'] as $permissionName) {
            Permission::findOrCreate($permissionName, $guard);
        }

        foreach (['supervisor', 'gm'] as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->first();

            if (! $role) {
                continue;
            }

            $role->givePermissionTo(['view-calendars', 'calendar-crud']);
        }
    }

    public function down(): void
    {
        // Intentionally left blank: scheduler access may have been granted outside this migration.
    }
};
