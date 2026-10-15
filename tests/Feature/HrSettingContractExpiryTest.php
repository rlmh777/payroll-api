<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HrSettingContractExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_expiry_schedule_defaults_and_can_be_updated(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->getJson('/api/hr-settings')
            ->assertOk()
            ->assertJsonPath('contractExpiryEnabled', true)
            ->assertJsonPath('contractExpiryOffsets.0.value', 3)
            ->assertJsonPath('contractExpiryOffsets.0.unit', 'months');

        $this->putJson('/api/hr-settings', [
            'contractExpiryEnabled' => true,
            'contractExpiryOffsets' => [
                ['value' => 2, 'unit' => 'months'],
                ['value' => 1, 'unit' => 'weeks'],
                ['value' => 1, 'unit' => 'days'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.contractExpiryOffsets.0.value', 2)
            ->assertJsonPath('data.contractExpiryOffsets.0.unit', 'months')
            ->assertJsonPath('data.birthdayVisibility', 'company');
    }

    private function hrUser(): User
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'view-hr-settings',
            'guard_name' => 'web',
        ]);

        $user = User::query()->create([
            'name' => 'HR',
            'username' => 'hr.settings',
            'email' => 'hr-settings@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->givePermissionTo($permission);

        return $user;
    }
}
