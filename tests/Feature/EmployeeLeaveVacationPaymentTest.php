<?php

namespace Tests\Feature;

use App\Enums\LeaveStatusCode;
use App\Models\Country;
use App\Models\District;
use App\Models\EmployeeLeave;
use App\Models\Gender;
use App\Models\LeaveStatus;
use App\Models\LeaveType;
use App\Models\Locality;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Modules\Hr\Services\EmployeePersonSync;
use App\Modules\Payroll\Services\PayrollFlatBaseScopeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeLeaveVacationPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        LeaveStatus::clearCache();
    }

    public function test_accounts_must_choose_vacation_payment_treatment(): void
    {
        [$user, $employeeId, $leaveTypeId] = $this->vacationActor();
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/employee-leaves', $this->leavePayload($employeeId, $leaveTypeId))->assertCreated();
        $leaveId = (string) $created->json('id');

        $this->assertSame(
            LeaveStatusCode::PendingAccountsConfirmation->value,
            EmployeeLeave::query()->findOrFail($leaveId)->leaveStatus?->code,
        );

        $blocked = $this->patchJson("/api/employee-leaves/{$leaveId}/status", [
            'action' => 'approve',
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'already been paid',
            (string) $blocked->json('errors.paymentTreatment.0'),
        );
    }

    public function test_already_paid_vacation_is_recorded_and_excluded_from_payroll_pay(): void
    {
        [$user, $employeeId, $leaveTypeId] = $this->vacationActor();
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/employee-leaves', $this->leavePayload($employeeId, $leaveTypeId))->assertCreated();
        $leaveId = (string) $created->json('id');

        $this->patchJson("/api/employee-leaves/{$leaveId}/status", [
            'action' => 'approve',
            'paymentTreatment' => 'already_paid',
        ])->assertOk()->assertJsonPath('paymentTreatment', 'already_paid');

        $leave = EmployeeLeave::query()->findOrFail($leaveId);
        $this->assertSame(0.0, (float) $leave->multiplier);
        $this->assertNull($leave->paidInPayrollRunId);
        $this->assertContains(
            $leave->leaveStatus?->code,
            [LeaveStatusCode::Scheduled->value, LeaveStatusCode::Taken->value],
        );

        $attribution = app(PayrollFlatBaseScopeService::class)->alreadyPaidLeaveAttribution(
            $employeeId,
            Carbon::parse('2026-10-12'),
            Carbon::parse('2026-10-16'),
            100.0,
        );

        $this->assertSame(3, $attribution['days']);
        $this->assertSame(300.0, $attribution['amount']);
        $this->assertSame($leaveId, $attribution['lines'][0]['leaveId'] ?? null);
    }

    public function test_include_in_next_payroll_keeps_vacation_payable(): void
    {
        [$user, $employeeId, $leaveTypeId] = $this->vacationActor();
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/employee-leaves', $this->leavePayload($employeeId, $leaveTypeId))->assertCreated();
        $leaveId = (string) $created->json('id');

        $this->patchJson("/api/employee-leaves/{$leaveId}/status", [
            'action' => 'approve',
            'paymentTreatment' => 'paid_with_payroll',
        ])->assertOk()->assertJsonPath('paymentTreatment', 'paid_with_payroll');

        $leave = EmployeeLeave::query()->findOrFail($leaveId);
        $this->assertSame(1.0, (float) $leave->multiplier);

        $attribution = app(PayrollFlatBaseScopeService::class)->alreadyPaidLeaveAttribution(
            $employeeId,
            Carbon::parse('2026-10-12'),
            Carbon::parse('2026-10-16'),
            100.0,
        );

        $this->assertSame(0, $attribution['days']);
        $this->assertSame(0.0, $attribution['amount']);
    }

    /**
     * @return array{0: \App\Models\User, 1: string, 2: int}
     */
    private function vacationActor(): array
    {
        $employee = $this->createEmployee('vacation-pay@example.com', 'Vacation', 'Worker');
        $user = $employee->user()->firstOrFail();
        $permissions = [];
        foreach (['leave-crud', 'view-leave', 'employees-crud'] as $name) {
            $permissions[] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
        $user->givePermissionTo($permissions);

        $leaveType = LeaveType::query()->create([
            'name' => 'Annual vacation pay test',
            'code' => 'VACATION_PAY_TEST',
            'isPaid' => true,
            'affectsBalance' => false,
            'requiresCertification' => false,
            'uncertifiedDaysAllowed' => 0,
            'isActive' => true,
            'sortOrder' => 1,
        ]);

        return [$user, (string) $employee->id, (int) $leaveType->id];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function leavePayload(string $employeeId, int $leaveTypeId, array $overrides = []): array
    {
        return array_merge([
            'employeeId' => $employeeId,
            'leaveTypeId' => $leaveTypeId,
            'startDate' => '2026-10-12',
            'endDate' => '2026-10-14',
            'duration' => 'All Days',
            'totalDays' => 3,
            'fromTime' => '08:00:00',
            'toTime' => '17:00:00',
            'assigned' => true,
        ], $overrides);
    }

    private function createEmployee(string $email, string $first, string $last)
    {
        $lookups = $this->employeeLookups();

        return EmployeePersonSync::create([
            'firstName' => $first,
            'lastName' => $last,
            'email' => $email,
            'phone' => '615-0004',
            'birthdate' => '1990-01-01',
            'address1' => '1 Main Street',
            'localityId' => $lookups['locality']->id,
            'genderId' => $lookups['gender']->id,
            'socialSecurityNumber' => substr(str_replace(['@', '.'], '', $email).'00000000', 0, 8),
            'paymentMethodId' => $lookups['payment']->id,
            'code' => substr(md5($email), 0, 6),
        ]);
    }

    /**
     * @return array{locality: Locality, gender: Gender, payment: PaymentMethod}
     */
    private function employeeLookups(): array
    {
        $country = Country::query()->firstOrCreate(
            ['code1' => 'BZ'],
            ['name' => 'Belize', 'code2' => 'BLZ', 'nationalityName' => 'Belizean'],
        );
        $district = District::query()->firstOrCreate(
            ['name' => 'Cayo', 'countryId' => $country->id],
        );
        $locality = Locality::query()->firstOrCreate(
            ['name' => 'San Ignacio', 'districtId' => $district->id],
        );
        $gender = Gender::query()->firstOrCreate(['name' => 'Female']);
        $payment = PaymentMethod::query()->firstOrCreate(['name' => 'Bank transfer']);

        return compact('locality', 'gender', 'payment');
    }
}
