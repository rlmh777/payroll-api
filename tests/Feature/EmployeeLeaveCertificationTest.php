<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\District;
use App\Models\Gender;
use App\Models\LeaveType;
use App\Models\Locality;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\User;
use App\Modules\Hr\Services\EmployeePersonSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeLeaveCertificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_uncertified_sick_days_are_allowed_without_attachments(): void
    {
        [$user, $employeeId, $leaveTypeId] = $this->certifiedSickActor();
        Sanctum::actingAs($user);

        $this->postJson('/api/employee-leaves', $this->leavePayload($employeeId, $leaveTypeId, [
            'startDate' => '2026-10-12',
            'endDate' => '2026-10-13',
            'duration' => 'All Days',
            'totalDays' => 2,
        ]))->assertCreated();
    }

    public function test_further_uncertified_days_are_rejected_until_proof_is_attached(): void
    {
        [$user, $employeeId, $leaveTypeId] = $this->certifiedSickActor();
        Sanctum::actingAs($user);

        $this->postJson('/api/employee-leaves', $this->leavePayload($employeeId, $leaveTypeId, [
            'startDate' => '2026-10-12',
            'endDate' => '2026-10-13',
            'duration' => 'All Days',
            'totalDays' => 2,
        ]))->assertCreated();

        $blocked = $this->postJson('/api/employee-leaves', $this->leavePayload($employeeId, $leaveTypeId, [
            'startDate' => '2026-10-14',
            'endDate' => '2026-10-14',
            'duration' => 'Full Day',
            'totalDays' => 1,
        ]))->assertStatus(422);

        $this->assertStringContainsString(
            'Medical documentation',
            (string) $blocked->json('errors.attachments.0'),
        );

        $this->post('/api/employee-leaves', array_merge(
            $this->leavePayload($employeeId, $leaveTypeId, [
                'startDate' => '2026-10-14',
                'endDate' => '2026-10-14',
                'duration' => 'Full Day',
                'totalDays' => 1,
            ]),
            ['attachments' => [UploadedFile::fake()->create('sick-note.pdf', 20, 'application/pdf')]],
        ))->assertCreated();
    }

    public function test_a_request_longer_than_the_uncertified_allowance_requires_proof(): void
    {
        [$user, $employeeId, $leaveTypeId] = $this->certifiedSickActor();
        Sanctum::actingAs($user);

        $this->postJson('/api/employee-leaves', $this->leavePayload($employeeId, $leaveTypeId, [
            'startDate' => '2026-10-12',
            'endDate' => '2026-10-14',
            'duration' => 'All Days',
            'totalDays' => 3,
        ]))->assertStatus(422);

        $this->post('/api/employee-leaves', array_merge(
            $this->leavePayload($employeeId, $leaveTypeId, [
                'startDate' => '2026-10-12',
                'endDate' => '2026-10-14',
                'duration' => 'All Days',
                'totalDays' => 3,
            ]),
            ['attachments' => [UploadedFile::fake()->create('sick-note.pdf', 20, 'application/pdf')]],
        ))->assertCreated();
    }

    public function test_leave_types_that_do_not_require_certification_stay_optional(): void
    {
        [$user, $employeeId] = $this->leaveActor();
        $leaveType = LeaveType::query()->create([
            'name' => 'Vacation test',
            'code' => 'VACATION_CERT_TEST',
            'isPaid' => true,
            'affectsBalance' => false,
            'requiresCertification' => false,
            'uncertifiedDaysAllowed' => 0,
            'isActive' => true,
            'sortOrder' => 10,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/employee-leaves', $this->leavePayload($employeeId, (int) $leaveType->id, [
            'startDate' => '2026-10-12',
            'endDate' => '2026-10-14',
            'duration' => 'All Days',
            'totalDays' => 3,
        ]))->assertCreated();
    }

    /**
     * @return array{0: User, 1: string, 2: int}
     */
    private function certifiedSickActor(): array
    {
        [$user, $employeeId] = $this->leaveActor();
        $leaveType = LeaveType::query()->create([
            'name' => 'Certified sick test',
            'code' => 'SICK_CERT_TEST',
            'isPaid' => true,
            'affectsBalance' => false,
            'requiresCertification' => true,
            'uncertifiedDaysAllowed' => 2,
            'isActive' => true,
            'sortOrder' => 20,
        ]);

        return [$user, $employeeId, (int) $leaveType->id];
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function leaveActor(): array
    {
        $employee = $this->createEmployee('sick@example.com', 'Sick', 'Worker');
        $user = $employee->user()->firstOrFail();
        $permissions = [];
        foreach (['leave-crud', 'view-leave'] as $name) {
            $permissions[] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
        $user->givePermissionTo($permissions);

        return [$user, (string) $employee->id];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function leavePayload(string $employeeId, int $leaveTypeId, array $overrides): array
    {
        return array_merge([
            'employeeId' => $employeeId,
            'leaveTypeId' => $leaveTypeId,
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
            'phone' => '615-0003',
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
