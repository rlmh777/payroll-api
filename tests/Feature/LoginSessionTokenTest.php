<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LoginSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class LoginSessionTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_token_expires_in_24_hours_and_logout_deletes_it(): void
    {
        $this->user();

        $login = $this->postJson('/api/login', [
            'username' => 'alovelace',
            'password' => 'secret-pass',
        ])->assertOk();

        $plain = $login->json('token');
        $this->assertNotEmpty($plain);

        $accessToken = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($accessToken);
        $this->assertTrue(LoginSessionToken::isLoginSession($accessToken));
        $this->assertTrue(
            $accessToken->expires_at->between(
                now()->addHours(24)->subMinute(),
                now()->addHours(24)->addMinute(),
            )
        );

        $this->withToken($plain)->postJson('/api/logout')->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($plain));
        $this->withToken($plain)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_activity_refreshes_a_login_session_but_not_a_named_api_token(): void
    {
        $user = $this->user();
        $login = $this->postJson('/api/login', [
            'username' => 'alovelace',
            'password' => 'secret-pass',
        ])->assertOk();
        $session = $login->json('token');

        $named = $user->createToken('hikvision-agent', ['*'], now()->addYear());
        $namedExpiry = $named->accessToken->expires_at->copy();

        $this->travel(2)->hours();

        $this->withToken($session)->getJson('/api/user')->assertOk();
        $this->withToken($named->plainTextToken)->getJson('/api/user')->assertOk();

        $sessionToken = PersonalAccessToken::findToken($session);
        $this->assertNotNull($sessionToken);
        $this->assertTrue($sessionToken->expires_at->greaterThan(now()->addHours(23)));

        $this->assertTrue(
            $named->accessToken->fresh()->expires_at->equalTo($namedExpiry)
        );
    }

    public function test_expired_login_token_is_rejected_and_logout_still_deletes_it(): void
    {
        $this->user();
        $login = $this->postJson('/api/login', [
            'username' => 'alovelace',
            'password' => 'secret-pass',
        ])->assertOk();
        $plain = $login->json('token');

        $accessToken = PersonalAccessToken::findToken($plain);
        $accessToken->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withToken($plain)->getJson('/api/user')->assertUnauthorized();
        $this->withToken($plain)->postJson('/api/logout')->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($plain));
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret-pass'),
        ]);
    }
}
