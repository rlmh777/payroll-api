<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use App\Models\UserRole;
use Illuminate\Support\Str;

class UserRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'johndoe@gmail.com'],
            [
                'name' => 'John Doe',
                'password' => '1234',
            ]
        );

        $role = Role::firstOrCreate(['name' => 'super-admin']);

        // Assign via Spatie for permission resolution
        if (!$user->hasRole($role->name)) {
            $user->assignRole($role->name);
        }

        // Ensure explicit pivot row exists in user_roles
        UserRole::firstOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
            ],
            [
                'id' => (string) Str::uuid(),
            ]
        );
    }
}


