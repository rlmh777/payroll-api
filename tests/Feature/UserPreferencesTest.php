<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Permission;
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
            ->assertJsonPath('preferences.onboardingCompleted', false)
            ->assertJsonPath('preferences.textSize', 'sm');

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

    public function test_user_can_set_and_clear_default_menu(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($user);

        $this->createMenu('payroll', '/payroll/overview', 'Overview');
        $this->createMenu('hr', '/hr/employees', 'Employees');

        $this->putJson('/api/user/preferences', [
            'defaultModule' => 'payroll',
            'defaultMenu' => '/payroll/overview',
        ])
            ->assertOk()
            ->assertJsonPath('preferences.defaultModule', 'payroll')
            ->assertJsonPath('preferences.defaultMenu', '/payroll/overview');

        $this->assertSame('/payroll/overview', $user->fresh()->defaultMenu());

        $this->putJson('/api/user/preferences', [
            'defaultMenu' => null,
        ])
            ->assertOk()
            ->assertJsonPath('preferences.defaultMenu', null);

        $this->assertNull($user->fresh()->defaultMenu());
    }

    public function test_default_menu_must_belong_to_selected_application(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($user);

        $this->createMenu('payroll', '/payroll/overview', 'Overview');
        $this->createMenu('hr', '/hr/employees', 'Employees');

        $this->putJson('/api/user/preferences', [
            'defaultModule' => 'payroll',
            'defaultMenu' => '/hr/employees',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['defaultMenu']);
    }

    public function test_changing_application_clears_menu_from_other_module(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
            'preferences' => [
                'default_module' => 'payroll',
                'default_menu' => '/payroll/overview',
            ],
        ]);
        Sanctum::actingAs($user);

        $this->createMenu('payroll', '/payroll/overview', 'Overview');
        $this->createMenu('hr', '/hr/employees', 'Employees');

        $this->putJson('/api/user/preferences', [
            'defaultModule' => 'hr',
        ])
            ->assertOk()
            ->assertJsonPath('preferences.defaultModule', 'hr')
            ->assertJsonPath('preferences.defaultMenu', null);

        $this->assertNull($user->fresh()->defaultMenu());
    }

    public function test_user_can_update_text_size(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/user/preferences', [
            'textSize' => 'lg',
        ])
            ->assertOk()
            ->assertJsonPath('preferences.textSize', 'lg');

        $this->assertSame('lg', $user->fresh()->textSize());

        $this->putJson('/api/user/preferences', [
            'textSize' => 'sm',
        ])
            ->assertOk()
            ->assertJsonPath('preferences.textSize', 'sm');
    }

    public function test_user_cannot_set_default_application_without_permission(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->grant($user, 'view-payroll-overview');
        Sanctum::actingAs($user);

        $this->createMenu('payroll', '/payroll/overview', 'Overview', 'view-payroll-overview');
        $this->createMenu('hr', '/hr/employees', 'Employees', 'view-employees');

        $this->putJson('/api/user/preferences', [
            'defaultModule' => 'hr',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['defaultModule']);
    }

    public function test_user_cannot_choose_default_application_when_only_one_is_accessible(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($user);

        $this->createMenu('payroll', '/payroll/overview', 'Overview');

        $this->putJson('/api/user/preferences', [
            'defaultModule' => 'hr',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['defaultModule']);

        $this->putJson('/api/user/preferences', [
            'defaultModule' => 'payroll',
            'defaultMenu' => '/payroll/overview',
        ])
            ->assertOk()
            ->assertJsonPath('preferences.defaultModule', 'payroll');
    }

    public function test_text_size_must_be_one_of_the_allowed_sizes(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/user/preferences', [
            'textSize' => 'xl',
        ])->assertUnprocessable();
    }

    private function createMenu(
        string $moduleCode,
        string $route,
        string $title,
        ?string $permission = null,
    ): void {
        Menu::query()->create([
            'title' => $title,
            'route' => $route,
            'module_code' => $moduleCode,
            'permission' => $permission,
            'type' => 'menu',
            'order' => 1,
            'is_active' => true,
        ]);
    }

    private function grant(User $user, string $name): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo($permission);
    }
}
