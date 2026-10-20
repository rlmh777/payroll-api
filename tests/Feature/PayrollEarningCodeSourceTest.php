<?php

namespace Tests\Feature;

use App\Models\PayrollEarningCode;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayrollEarningCodeSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_holiday_codes_with_different_multipliers_can_be_updated(): void
    {
        Sanctum::actingAs($this->editor());

        $timeAndAHalf = PayrollEarningCode::query()->create([
            'code' => 'HOLIDAY',
            'name' => 'Holiday Pay (1.5 time)',
            'source' => PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
            'pay_multiplier' => 1.5,
            'post_to_department_account' => true,
            'is_taxable' => true,
            'is_ss_subject' => true,
            'is_active' => true,
            'sort_order' => 3,
        ]);
        PayrollEarningCode::query()->create([
            'code' => 'HOLIDAY_DT',
            'name' => 'Holiday Pay (double time)',
            'source' => PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
            'pay_multiplier' => 2,
            'post_to_department_account' => true,
            'is_taxable' => true,
            'is_ss_subject' => true,
            'is_active' => true,
            'sort_order' => 4,
        ]);

        $this->putJson("/api/payroll-earning-codes/{$timeAndAHalf->id}", [
            'code' => 'HOLIDAY',
            'name' => 'Holiday Pay (1.5 time)',
            'source' => PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
            'post_to_department_account' => true,
            'split_by_timesheet_department' => true,
            'is_taxable' => true,
            'is_ss_subject' => true,
            'is_active' => true,
            'sort_order' => 3,
        ])->assertOk();

        $fresh = $timeAndAHalf->fresh();
        $this->assertTrue((bool) $fresh->split_by_timesheet_department);
        $this->assertEquals(1.5, (float) $fresh->pay_multiplier);
    }

    public function test_same_source_and_multiplier_is_still_rejected(): void
    {
        Sanctum::actingAs($this->editor());

        PayrollEarningCode::query()->create([
            'code' => 'HOLIDAY',
            'name' => 'Holiday Pay (1.5 time)',
            'source' => PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
            'pay_multiplier' => 1.5,
            'post_to_department_account' => true,
            'is_taxable' => true,
            'is_ss_subject' => true,
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $this->postJson('/api/payroll-earning-codes', [
            'code' => 'HOLIDAY_2',
            'name' => 'Holiday duplicate',
            'source' => PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
            'pay_multiplier' => 1.5,
            'post_to_department_account' => true,
            'is_taxable' => true,
            'is_ss_subject' => true,
            'is_active' => true,
            'sort_order' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors(['source']);
    }

    private function editor(): User
    {
        $user = User::query()->create([
            'name' => 'Payroll Editor',
            'username' => 'earn.'.uniqid(),
            'email' => uniqid('earn-').'@example.com',
            'password' => bcrypt('secret-pass'),
        ]);
        $user->givePermissionTo(Permission::query()->firstOrCreate([
            'name' => 'payroll-earning-code-crud',
            'guard_name' => 'web',
        ]));

        return $user;
    }
}
