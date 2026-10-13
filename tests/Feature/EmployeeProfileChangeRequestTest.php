<?php

namespace Tests\Feature;

use App\Enums\PipelineAssigneeType;
use App\Models\Country;
use App\Models\District;
use App\Models\Gender;
use App\Models\Locality;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\PipelineTemplate;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeePersonSync;
use App\Modules\Workflow\Services\PipelineEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeProfileChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitted_email_is_not_applied_until_hr_approves(): void
    {
        $employeeUser = $this->employeeUser('lloyd@example.com', 'Lloyd', 'Edwards');
        $hr = $this->userWithPermissions(['employees-crud', 'view-employees']);

        Sanctum::actingAs($employeeUser);
        $created = $this->postJson('/api/employee-profile-changes', [
            'email' => 'lloyd.new@example.com',
            'phone' => '615-9999',
            'address1' => '9 New Street',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.pipeline.currentStep.key', 'hr');

        $id = $created->json('data.id');
        $employeeUser->employee->refresh()->load('person');
        $this->assertSame('lloyd@example.com', $employeeUser->employee->email);
        $this->assertSame('615-0003', $employeeUser->employee->phone);
        $this->assertSame('1 Main Street', $employeeUser->employee->address1);

        $this->getJson('/api/employees/me')
            ->assertOk()
            ->assertJsonPath('pendingProfileChange.id', $id)
            ->assertJsonPath('email', 'lloyd@example.com');

        Sanctum::actingAs($hr);
        $this->getJson('/api/employee-profile-changes/inbox')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->postJson("/api/employee-profile-changes/{$id}/actions", [
            'action' => 'approve',
            'note' => 'Verified with employee.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.applied_at', fn ($value) => filled($value));

        $employeeUser->employee->refresh()->load('person');
        $this->assertSame('lloyd.new@example.com', $employeeUser->employee->email);
        $this->assertSame('615-9999', $employeeUser->employee->phone);
        $this->assertSame('9 New Street', $employeeUser->employee->address1);
    }

    public function test_rejected_request_does_not_update_the_employee_record(): void
    {
        $employeeUser = $this->employeeUser('ada@example.com', 'Ada', 'Lovelace');
        $hr = $this->userWithPermissions(['employees-crud']);

        Sanctum::actingAs($employeeUser);
        $id = $this->postJson('/api/employee-profile-changes', [
            'email' => 'ada.new@example.com',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($hr);
        $this->postJson("/api/employee-profile-changes/{$id}/actions", [
            'action' => 'reject',
            'note' => 'Use company email.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $employeeUser->employee->refresh()->load('person');
        $this->assertSame('ada@example.com', $employeeUser->employee->email);
    }

    public function test_supervisor_then_hr_pipeline_applies_only_after_final_approval(): void
    {
        $this->prependSupervisorStep();

        $supervisor = $this->employeeUser('sam@example.com', 'Sam', 'Boss');
        $subject = $this->employeeUser('alex@example.com', 'Alex', 'River');
        $hr = $this->userWithPermissions(['employees-crud', 'view-employees']);

        $subjectEmployee = $subject->fresh()->employee;
        $subjectEmployee->supervisorId = $supervisor->employee->id;
        $subjectEmployee->save();

        Sanctum::actingAs($subject);
        $id = $this->postJson('/api/employee-profile-changes', [
            'phone' => '615-4242',
        ])
            ->assertCreated()
            ->assertJsonPath('data.pipeline.currentStep.key', 'supervisor')
            ->json('data.id');

        $this->assertSame('615-0003', $subject->employee->fresh()->load('person')->phone);

        Sanctum::actingAs($supervisor);
        $this->getJson('/api/employee-profile-changes/inbox')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->postJson("/api/employee-profile-changes/{$id}/actions", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.pipeline.currentStep.key', 'hr');

        $this->assertSame('615-0003', $subject->employee->fresh()->load('person')->phone);

        Sanctum::actingAs($hr);
        $this->postJson("/api/employee-profile-changes/{$id}/actions", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('615-4242', $subject->employee->fresh()->load('person')->phone);
    }

    public function test_employee_cannot_read_another_persons_profile_change(): void
    {
        $owner = $this->employeeUser('owner@example.com', 'Ow', 'Ner');
        $other = $this->employeeUser('other@example.com', 'Ot', 'Her');

        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/employee-profile-changes', [
            'email' => 'owner.new@example.com',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($other);
        $this->getJson("/api/employee-profile-changes/{$id}")->assertForbidden();
        $this->getJson('/api/employee-profile-changes')->assertForbidden();
        $this->postJson("/api/employee-profile-changes/{$id}/actions", ['action' => 'approve'])->assertForbidden();
    }

    public function test_second_pending_request_is_rejected(): void
    {
        $user = $this->employeeUser('twice@example.com', 'Tw', 'Ice');
        Sanctum::actingAs($user);

        $this->postJson('/api/employee-profile-changes', ['phone' => '615-1111'])->assertCreated();
        $this->postJson('/api/employee-profile-changes', ['phone' => '615-2222'])->assertStatus(422);
    }

    private function prependSupervisorStep(): void
    {
        $template = PipelineTemplate::query()
            ->where('key', 'employee_profile_change')
            ->where('subject_type', PipelineEngine::SUBJECT_PROFILE_CHANGE)
            ->firstOrFail();

        $template->steps()->increment('sort_order');
        $template->steps()->create([
            'key' => 'supervisor',
            'label' => 'Supervisor review',
            'sort_order' => 1,
            'assignee_type' => PipelineAssigneeType::Supervisor->value,
            'domain_status' => 'pending_supervisor',
            'actions' => ['approve', 'reject', 'return', 'cancel'],
            'ui_config' => ['mode' => 'review'],
        ]);
    }

    private function userWithPermissions(array $names): User
    {
        $permissions = [];
        foreach ($names as $name) {
            $permissions[] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $user = User::query()->create([
            'name' => $names[0],
            'username' => str_replace('@', '.', $names[0].uniqid()),
            'email' => str_replace('|', '.', $names[0]).uniqid().'@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function employeeUser(string $email, string $first, string $last): User
    {
        $employee = $this->createEmployee($email, $first, $last);

        return $employee->user()->firstOrFail();
    }

    private function createEmployee(string $email, string $first, string $last): Employee
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
