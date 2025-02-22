<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
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
        Permission::create(['name' => 'update']);
        Permission::create(['name' => 'delete ']);
        Permission::create(['name' => 'save ']);

        //Role::create(['name' => 'admin']);

        // update cache to know about the newly created permissions (required if using WithoutModelEvents in seeders)
        //app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();


        //     // create roles and assign created permissions

        //     $role = Role::create(['name' => 'accountant'])
        //         ->givePermissionTo(permissions: ['save', 'update']);

        //     $role = Role::create(['name' => 'admin']);
        //     $role->givePermissionTo(Permission::all());
    }
}