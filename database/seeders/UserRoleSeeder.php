<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use App\Models\UserRole;
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

        $adminRole = Role::firstOrCreate(['name' => 'super-admin']);
        $supervisorRole = Role::firstOrCreate(['name' => 'supervisor']);
        $employeeRole = Role::firstOrCreate(['name' => 'employee']);

        // Assign via Spatie for permission resolution
        if (!$adminUser->hasRole($adminRole->name)) {
            $adminUser->assignRole($adminRole->name);
        }
        if (!$supervisorUser->hasRole($supervisorRole->name)) {
            $supervisorUser->assignRole($supervisorRole->name);
        }
        if (!$employeeUser->hasRole($employeeRole->name)) {
            $employeeUser->assignRole($employeeRole->name);
        }

        // Ensure explicit pivot row exists in user_roles
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

