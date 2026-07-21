<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminUser = User::firstOrCreate(
            ['email' => 'johndoe@gmail.com'],
            [
                'name' => 'John Doe',
                'password' => Hash::make('Password123!'),
            ]
        );

        $supervisorUser = User::firstOrCreate(
            ['email' => 'supervisor@example.com'],
            [
                'name' => 'Supervisor User',
                'password' => Hash::make('Password123!'),
            ]
        );

        $employeeUser = User::firstOrCreate(
            ['email' => 'employee@example.com'],
            [
                'name' => 'Employee User',
                'password' => Hash::make('Password123!'),
            ]
        );

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $supervisorRole = Role::firstOrCreate(['name' => 'supervisor']);
        $employeeRole = Role::firstOrCreate(['name' => 'employee']);

        $employeePermissions = [
            'view-dashboard',
            'view-leave',
            'view-leave-types',
        ];

        $supervisorPermissions = [
            'view-dashboard',
            'view-employees',
            'view-leave',
            'view-leave-types',
            'leave-crud',
            'view-timesheets',
            'timesheets-crud',
            'view-payroll-allowances',
            'payroll-allowances-crud',
        ];

        foreach (array_unique([...$employeePermissions, ...$supervisorPermissions]) as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $employeeRole->syncPermissions($employeePermissions);
        $supervisorRole->syncPermissions($supervisorPermissions);

        // Admin gets every permission via the admin role.
        // Leave and employee menu/API permissions must always be present for johndoe.
        $adminRole->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
        $adminUser->syncRoles([$adminRole]);
        $adminUser->syncPermissions(Permission::query()->where('guard_name', 'web')->get());

        if (! $supervisorUser->hasRole($supervisorRole->name)) {
            $supervisorUser->assignRole($supervisorRole->name);
        }
        if (! $employeeUser->hasRole($employeeRole->name)) {
            $employeeUser->assignRole($employeeRole->name);
        }

        // Ensure explicit pivot row exists in user_roles (admin only for johndoe)
        UserRole::query()
            ->where('user_id', $adminUser->id)
            ->where('role_id', '!=', $adminRole->id)
            ->delete();

        UserRole::firstOrCreate(
            [
                'user_id' => $adminUser->id,
                'role_id' => $adminRole->id,
            ],
            [
                'id' => (string) Str::uuid(),
            ]
        );

        UserRole::firstOrCreate(
            [
                'user_id' => $supervisorUser->id,
                'role_id' => $supervisorRole->id,
            ],
            [
                'id' => (string) Str::uuid(),
            ]
        );

        UserRole::firstOrCreate(
            [
                'user_id' => $employeeUser->id,
                'role_id' => $employeeRole->id,
            ],
            [
                'id' => (string) Str::uuid(),
            ]
        );
    }
}
