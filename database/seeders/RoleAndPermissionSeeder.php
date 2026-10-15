<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Ensure the admin role exists.
     *
     * Resource permissions (view-*, *-crud, manager-*) are created by MenuSeeder
     * and feature migrations — not as bare update/delete/save names.
     */
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'admin']);
    }
}
