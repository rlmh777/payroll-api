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
use App\Models\User;
use App\Modules\Hr\Services\EmployeePersonSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeLeaveAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function employee_can_create_and_read_only_their_own_leave(): void
    {
        [$employeeUser, $employeeId] = $this->employeeActor('self-leave@example.com', 'Self', 'Leave');
        [$otherUser, $otherId] = $this->employeeActor('other-leave@example.com', 'Other', 'Leave');
        $leaveTypeId = $this->leaveTypeId();

        Sanctum::actingAs($employeeUser);

        $created = $this->postJson('/api/employee-leaves', $this->leavePayload($employeeId, $leaveTypeId))
            ->assertCreated();
        $leaveId = $created->json('id') ?? $created->json('0.id');
        $this->assertNotEmpty($leaveId);

        $this->postJson('/api/employee-leaves', $this->leavePayload($otherId, $leaveTypeId))
            ->assertForbidden();

        $this->getJson('/api/employee-leaves?employeeId='.$otherId)
            ->assertForbidden();

        $this->getJson('/api/employee-leaves?employeeId='.$employeeId)
            ->assertOk()
            ->assertJsonPath('data.0.id', $leaveId);

        $this->getJson('/api/employee-leaves/'.$leaveId)->assertOk();

        Sanctum::actingAs($otherUser);
        $this->getJson('/api/employee-leaves/'.$leaveId)->assertForbidden();
    }

    #[Test]
    public function employee_can_update_and_delete_unapproved_leave_only(): void
    {
        [$employeeUser, $employeeId] = $this->employeeActor('mutate-leave@example.com', 'Mutate', 'Leave');
        $leaveTypeId = $this->leaveTypeId();
        Sanctum::actingAs($employeeUser);

        $pendingId = $this->postJson('/api/employee-leaves', $this->leavePayload($employeeId, $leaveTypeId))
            ->assertCreated()
            ->json('id');

        $this->putJson('/api/employee-leaves/'.$pendingId, [
            'notes' => 'Updated while pending',
        ])->assertOk()->assertJsonPath('notes', 'Updated while pending');

        $cancelId = $this->postJson('/api/employee-leaves', $this->leavePayload($employeeId, $leaveTypeId, [
            'startDate' => '2026-10-13',
            'endDate' => '2026-10-13',
        ]))->assertCreated()->json('id');

        $this->patchJson('/api/employee-leaves/'.$cancelId.'/status', [
            'action' => 'cancel',
        ])->assertOk();

        $scheduled = $this->createLeave($employeeId, $leaveTypeId, LeaveStatusCode::Scheduled);

        $this->putJson('/api/employee-leaves/'.$scheduled->id, [
            'notes' => 'Should not update',
        ])->assertForbidden();

        $this->deleteJson('/api/employee-leaves/'.$scheduled->id)->assertForbidden();
        $this->patchJson('/api/employee-leaves/'.$scheduled->id.'/status', [
            'action' => 'cancel',
        ])->assertForbidden();

        $this->deleteJson('/api/employee-leaves/'.$pendingId)->assertNoContent();
    }

    #[Test]
    public function supervisor_can_manage_unapproved_leave_for_reports_only(): void
    {
        [$supervisorUser, $supervisorId] = $this->employeeActor(
            'boss-leave@example.com',
            'Boss',
            'Leave',
            ['view-leave', 'employee-leave-crud', 'leave-crud'],
        );
        $report = $this->createEmployee('report-leave@example.com', 'Report', 'Leave', $supervisorId);
        $stranger = $this->createEmployee('stranger-leave@example.com', 'Stranger', 'Leave');
        $leaveTypeId = $this->leaveTypeId();

        Sanctum::actingAs($supervisorUser);

        $reportLeaveId = $this->postJson(
            '/api/employee-leaves',
            $this->leavePayload((string) $report->id, $leaveTypeId),
        )->assertCreated()->json('id');

        $this->postJson(
            '/api/employee-leaves',
            $this->leavePayload((string) $stranger->id, $leaveTypeId),
        )->assertForbidden();

        $this->putJson('/api/employee-leaves/'.$reportLeaveId, [
            'notes' => 'Supervisor edit',
        ])->assertOk();

        $this->deleteJson('/api/employee-leaves/'.$reportLeaveId)->assertNoContent();

        $approved = $this->createLeave((string) $report->id, $leaveTypeId, LeaveStatusCode::Scheduled);
        $this->putJson('/api/employee-leaves/'.$approved->id, [
            'notes' => 'Too late',
        ])->assertForbidden();
        $this->deleteJson('/api/employee-leaves/'.$approved->id)->assertForbidden();
    }

    #[Test]
    public function employee_cannot_assign_leave_that_skips_approval(): void
    {
        [$employeeUser, $employeeId] = $this->employeeActor('assign-block@example.com', 'Assign', 'Block');
        Sanctum::actingAs($employeeUser);

        $this->postJson('/api/employee-leaves', $this->leavePayload($employeeId, $this->leaveTypeId(), [
            'assigned' => true,
        ]))->assertForbidden();
    }

    /**
     * @param list<string> $permissions
     * @return array{0: User, 1: string}
     */
    private function employeeActor(
        string $email,
        string $first,
        string $last,
        array $permissions = ['view-leave', 'employee-leave-crud'],
    ): array {
        $employee = $this->createEmployee($email, $first, $last);
        $user = $employee->user()->firstOrFail();
        $models = [];
        foreach ($permissions as $name) {
            $models[] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
        $user->givePermissionTo($models);

        return [$user, (string) $employee->id];
    }

    private function createEmployee(
        string $email,
        string $first,
        string $last,
        ?string $supervisorId = null,
    ) {
        $lookups = $this->employeeLookups();

        return EmployeePersonSync::create(array_filter([
            'firstName' => $first,
            'lastName' => $last,
            'email' => $email,
            'phone' => '615-0005',
            'birthdate' => '1990-01-01',
            'address1' => '1 Main Street',
            'localityId' => $lookups['locality']->id,
            'genderId' => $lookups['gender']->id,
            'socialSecurityNumber' => substr(str_replace(['@', '.'], '', $email).'00000000', 0, 8),
            'paymentMethodId' => $lookups['payment']->id,
            'code' => substr(md5($email), 0, 6),
            'supervisorId' => $supervisorId,
        ]));
    }

    private function leaveTypeId(): int
    {
        return (int) LeaveType::query()->firstOrCreate(
            ['code' => 'ACCESS_TEST_LEAVE'],
            [
                'name' => 'Access test leave',
                'isPaid' => true,
                'affectsBalance' => false,
                'requiresCertification' => false,
                'uncertifiedDaysAllowed' => 0,
                'isActive' => true,
                'sortOrder' => 90,
            ],
        )->id;
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
            'endDate' => '2026-10-12',
            'duration' => 'Full Day',
            'totalDays' => 1,
            'fromTime' => '08:00:00',
            'toTime' => '17:00:00',
        ], $overrides);
    }

    private function createLeave(string $employeeId, int $leaveTypeId, LeaveStatusCode $status): EmployeeLeave
    {
        LeaveStatus::clearCache();

        return EmployeeLeave::query()->create([
            'employeeId' => $employeeId,
            'leaveTypeId' => $leaveTypeId,
            'leaveStatusId' => LeaveStatus::idForCode($status),
            'startDate' => '2026-10-19',
            'endDate' => '2026-10-19',
            'duration' => 'Full Day',
            'totalDays' => 1,
            'fromTime' => '08:00:00',
            'toTime' => '17:00:00',
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
