<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_multiple_roles()
    {
        // Create roles
        $adminRole = Role::create(['name' => 'admin']);
        $managerRole = Role::create(['name' => 'manager']);
        $userRole = Role::create(['name' => 'user']);

        // Create user
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password')
        ]);

        // Assign multiple roles
        $user->assignRole([$adminRole, $managerRole]);

        // Assert user has multiple roles
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('manager'));
        $this->assertFalse($user->hasRole('user'));
        $this->assertCount(2, $user->roles);
    }

    public function test_user_roles_can_be_synced()
    {
        // Create roles
        $adminRole = Role::create(['name' => 'admin']);
        $managerRole = Role::create(['name' => 'manager']);
        $userRole = Role::create(['name' => 'user']);

        // Create user
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password')
        ]);

        // Assign initial roles
        $user->assignRole([$adminRole, $managerRole]);
        $this->assertCount(2, $user->roles);

        // Sync roles (replace all existing roles)
        $user->syncRoles([$userRole]);
        $user->refresh();

        // Assert only the new role exists
        $this->assertTrue($user->hasRole('user'));
        $this->assertFalse($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('manager'));
        $this->assertCount(1, $user->roles);
    }

    public function test_user_roles_can_be_removed()
    {
        // Create roles
        $adminRole = Role::create(['name' => 'admin']);
        $managerRole = Role::create(['name' => 'manager']);

        // Create user
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password')
        ]);

        // Assign roles
        $user->assignRole([$adminRole, $managerRole]);
        $this->assertCount(2, $user->roles);

        // Remove specific role
        $user->removeRole($adminRole);
        $user->refresh();

        // Assert only manager role remains
        $this->assertFalse($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('manager'));
        $this->assertCount(1, $user->roles);
    }
}
