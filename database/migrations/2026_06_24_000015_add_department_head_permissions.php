<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'view-department-heads']);
        Permission::firstOrCreate(['name' => 'department-head-crud']);

        $role = Role::query()->where('name', 'super-admin')->first();
        if ($role) {
            $role->givePermissionTo(['view-department-heads', 'department-head-crud']);
        }
    }

    public function down(): void
    {
        // Permissions are shared; leave in place on rollback.
    }
};
