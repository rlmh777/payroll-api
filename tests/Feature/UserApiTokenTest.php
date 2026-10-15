<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_and_revoke_a_user_api_token(): void
    {
        $admin = $this->usersAdmin();
        $target = User::query()->create([
            'name' => 'Integration Bot',
            'username' => 'integration',
            'email' => 'integration@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($admin);

        $created = $this->postJson("/api/users/{$target->id}/tokens", [
            'tokenName' => 'hikvision-agent',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'hikvision-agent');

        $plain = $created->json('token');
        $this->assertNotEmpty($plain);
        $this->assertSame(1, $target->tokens()->count());
        $expiresAt = Carbon::parse($created->json('data.expiresAt'));
        $this->assertTrue($expiresAt->between(now()->addYear()->subMinute(), now()->addYear()->addDay()));

        $this->getJson("/api/users/{$target->id}/tokens")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'hikvision-agent')
            ->assertJsonMissingPath('data.0.token');

        $tokenId = $created->json('data.id');
        $this->deleteJson("/api/users/{$target->id}/tokens/{$tokenId}")
            ->assertOk();

        $this->assertSame(0, $target->fresh()->tokens()->count());
    }

    public function test_user_without_permission_cannot_create_tokens_for_others(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);
        $target = User::query()->create([
            'name' => 'Integration Bot',
            'username' => 'integration',
            'email' => 'integration@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/users/{$target->id}/tokens", [
            'tokenName' => 'hikvision-agent',
        ])->assertForbidden();
    }

    public function test_token_expires_on_the_requested_date_and_defaults_to_one_year(): void
    {
        $admin = $this->usersAdmin();
        $target = $this->integrationUser();
        Sanctum::actingAs($admin);

        $customExpiry = now()->addDays(45)->toDateString();
        $created = $this->postJson("/api/users/{$target->id}/tokens", [
            'tokenName' => 'custom-expiry',
            'expiresAt' => $customExpiry,
        ])->assertCreated();

        $this->assertTrue(
            Carbon::parse($created->json('data.expiresAt'))->isSameDay(Carbon::parse($customExpiry))
        );

        $this->postJson("/api/users/{$target->id}/tokens", [
            'tokenName' => 'past-token',
            'expiresAt' => now()->subDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_token_uses_the_target_users_permissions(): void
    {
        $target = $this->integrationUser();
        $viewEmployees = Permission::query()->firstOrCreate([
            'name' => 'view-employees',
            'guard_name' => 'web',
        ]);
        $target->givePermissionTo($viewEmployees);

        $plain = $target->createToken('integration-bot', ['*'], now()->addYear())->plainTextToken;

        $me = $this->withToken($plain)->getJson('/api/user')->assertOk();
        $this->assertSame($target->id, $me->json('id'));
        $this->assertContains('view-employees', $me->json('permissions'));
        $this->assertNotContains('manager-users', $me->json('permissions'));

        $this->withToken($plain)
            ->getJson('/api/users')
            ->assertForbidden();
    }

    public function test_expired_token_is_rejected(): void
    {
        $target = $this->integrationUser();
        $expired = $target->createToken('expired-bot', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($expired)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    private function integrationUser(): User
    {
        return User::query()->create([
            'name' => 'Integration Bot',
            'username' => 'integration',
            'email' => 'integration@example.com',
            'password' => bcrypt('secret'),
        ]);
    }

    private function usersAdmin(): User
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'manager-users',
            'guard_name' => 'web',
        ]);
        $admin = User::query()->create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $admin->givePermissionTo($permission);

        return $admin;
    }
}
