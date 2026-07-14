<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        //app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // create permissions
        Permission::firstOrCreate(['name' => 'update']);
        Permission::firstOrCreate(['name' => 'delete']);
        Permission::firstOrCreate(['name' => 'save']);

        // update cache to know about the newly created permissions (required if using WithoutModelEvents in seeders)
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::firstOrCreate(['name' => 'super-admin']);
        $role->syncPermissions(Permission::all());

    }
}