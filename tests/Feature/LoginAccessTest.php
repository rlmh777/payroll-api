<?php

namespace Tests\Feature;

use App\Models\AuthSetting;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoginAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_locks_after_too_many_failed_passwords(): void
    {
        $user = $this->makeUser();
        AuthSetting::current()->update([
            'lockout_max_attempts' => 3,
            'lockout_decay_minutes' => 15,
        ]);

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'wrong-pass',
        ])->assertStatus(400);

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'wrong-pass',
        ])->assertStatus(400);

        $locked = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'wrong-pass',
        ]);
        $locked->assertStatus(423);
        $this->assertStringContainsString('Too many failed', (string) $locked->json('message'));

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'secret-pass',
        ])->assertStatus(423);

        $this->assertNotNull($user->fresh()->locked_until);
    }

    public function test_lockout_expires_and_successful_login_clears_attempts(): void
    {
        $user = $this->makeUser();
        AuthSetting::current()->update([
            'lockout_max_attempts' => 2,
            'lockout_decay_minutes' => 15,
        ]);

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'wrong-pass',
        ])->assertStatus(400);
        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'wrong-pass',
        ])->assertStatus(423);

        $this->travel(16)->minutes();

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'secret-pass',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
    }

    public function test_login_rate_limiter_is_registered(): void
    {
        $passkey = $this->postJson('/api/login/passkey', []);
        $this->assertNotSame(500, $passkey->status());
        $this->assertStringNotContainsString(
            'Rate limiter [login] is not defined',
            (string) $passkey->json('message'),
        );

        $this->postJson('/api/login', [
            'username' => 'nobody',
            'password' => 'whatever',
        ])->assertStatus(400);
    }

    public function test_unknown_user_does_not_reveal_lockout(): void
    {
        $this->postJson('/api/login', [
            'username' => 'nobody',
            'password' => 'whatever',
        ])->assertStatus(400)->assertJsonPath('message', 'Mismatch Username or Password');
    }

    public function test_ip_allowlist_blocks_login_from_other_addresses(): void
    {
        $user = $this->makeUser(['allowed_ips' => ['203.0.113.10']]);

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'secret-pass',
        ])->assertStatus(403);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/login', [
                'username' => $user->username,
                'password' => 'secret-pass',
            ])
            ->assertOk();
    }

    public function test_cidr_allowlist_and_authenticated_requests(): void
    {
        $user = $this->makeUser(['allowed_ips' => ['10.8.0.0/24']]);
        Sanctum::actingAs($user);

        $this->getJson('/api/user')->assertStatus(403);

        $this->withServerVariables(['REMOTE_ADDR' => '10.8.0.42'])
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_admin_can_set_allowed_ips_and_unlock_a_user(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $user->forceFill([
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(20),
        ])->save();

        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$user->id}/security", [
            'allowed_ips' => ['203.0.113.8', '10.0.0.0/24'],
            'unlock' => true,
        ])
            ->assertOk()
            ->assertJsonPath('user.allowed_ips.0', '203.0.113.8')
            ->assertJsonPath('user.is_locked', false);

        $fresh = $user->fresh();
        $this->assertSame(['203.0.113.8', '10.0.0.0/24'], $fresh->allowed_ips);
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);

        $this->putJson("/api/users/{$user->id}/security", [
            'allowed_ips' => ['not-an-ip'],
        ])->assertStatus(422);
    }

    public function test_auth_settings_persist_lockout_policy(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->putJson('/api/auth-settings', [
            'lockout_max_attempts' => 8,
            'lockout_decay_minutes' => 30,
        ])
            ->assertOk()
            ->assertJsonPath('lockout_max_attempts', 8)
            ->assertJsonPath('lockout_decay_minutes', 30);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeUser(array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace.'.uniqid(),
            'email' => uniqid('ada-').'@example.com',
            'password' => bcrypt('secret-pass'),
        ], $attributes));
    }

    private function makeAdmin(): User
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'manager-users',
            'guard_name' => 'web',
        ]);
        $admin = $this->makeUser([
            'name' => 'Admin',
            'username' => 'admin.'.uniqid(),
            'email' => uniqid('admin-').'@example.com',
        ]);
        $admin->givePermissionTo($permission);

        return $admin;
    }
}
