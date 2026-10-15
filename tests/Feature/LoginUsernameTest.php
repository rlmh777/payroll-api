<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\LoginUserFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoginUsernameTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_users_by_username_or_email(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);

        $finder = app(LoginUserFinder::class);

        $this->assertTrue($user->is($finder->find('alovelace')));
        $this->assertTrue($user->is($finder->find('ADA@example.com')));
        $this->assertTrue($user->is($finder->find('Alovelace')));
    }

    public function test_password_login_accepts_username(): void
    {
        User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret-pass'),
        ]);

        $this->postJson('/api/login', [
            'username' => 'alovelace',
            'password' => 'secret-pass',
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'secret-pass',
        ])->assertOk();
    }

    public function test_auth_settings_persist_username_rules(): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'manager-users',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permission);

        $admin = User::query()->create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $admin->givePermissionTo($permission);
        $admin->assignRole($role);

        Sanctum::actingAs($admin);
        $this->putJson('/api/auth-settings', [
                'username_patterns' => ['last_first', 'first_initial_last'],
                'username_separator' => '_',
                'username_include_middle_initial' => false,
                'employee_login_domain' => 'chaacreek.com',
            ])
            ->assertOk()
            ->assertJsonPath('username_pattern', 'last_first')
            ->assertJsonPath('username_patterns.0', 'last_first')
            ->assertJsonPath('username_patterns.1', 'first_initial_last')
            ->assertJsonPath('username_separator', '_')
            ->assertJsonPath('username_include_middle_initial', false)
            ->assertJsonPath('employee_login_domain', 'chaacreek.com')
            ->assertJsonPath('username_preview', 'doe_john')
            ->assertJsonPath('username_preview_candidates.1', 'j_doe');
    }
}
