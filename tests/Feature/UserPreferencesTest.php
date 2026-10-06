<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_onboarding_preferences(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('preferences.onboardingEnabled', true)
            ->assertJsonPath('preferences.onboardingCompleted', false);

        $this->putJson('/api/user/preferences', [
            'onboardingEnabled' => false,
            'onboardingCompleted' => true,
        ])
            ->assertOk()
            ->assertJsonPath('preferences.onboardingEnabled', false)
            ->assertJsonPath('preferences.onboardingCompleted', true);

        $this->assertFalse($user->fresh()->preference('onboarding_enabled'));
        $this->assertTrue((bool) $user->fresh()->preference('onboarding_completed'));
    }

    public function test_user_can_update_onboarding_seen_map(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($user);

        $emptySeen = $this->getJson('/api/user')
            ->assertOk()
            ->json('preferences.onboardingSeen');
        $this->assertSame([], (array) $emptySeen);

        $this->putJson('/api/user/preferences', [
            'onboardingSeen' => [
                'employees' => true,
                'timesheet' => true,
                'unknown' => true,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('preferences.onboardingSeen.employees', true)
            ->assertJsonPath('preferences.onboardingSeen.timesheet', true)
            ->assertJsonMissingPath('preferences.onboardingSeen.unknown');

        $this->assertSame(
            ['employees' => true, 'timesheet' => true],
            $user->fresh()->preference('onboarding_seen'),
        );
    }
}
