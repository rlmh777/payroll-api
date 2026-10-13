<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\EmployeeGroup;
use App\Models\EmployeeGroupMember;
use App\Models\Gender;
use App\Models\Locality;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\PipelineInstance;
use App\Models\PipelineTemplate;
use App\Models\User;
use App\Modules\Hr\Models\FormAssignment;
use App\Modules\Hr\Models\FormTemplate;
use App\Modules\Hr\Services\EmployeePersonSync;
use App\Modules\Workflow\Services\PipelineEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FormAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_sees_and_submits_a_company_wide_required_form(): void
    {
        $admin = $this->formAdmin();
        Sanctum::actingAs($admin);

        $template = $this->feedbackTemplate();
        $created = $this->postJson("/api/form-templates/{$template->id}/assignments", [
            'name' => 'Q4 pulse',
            'audience_type' => FormAssignment::AUDIENCE_COMPANY,
            'show_on_login' => true,
            'is_required' => true,
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.audience_type', 'company');

        $assignmentId = $created->json('data.id');
        $employeeUser = $this->employeeUser('maya@example.com', 'Maya', 'Chan');

        Sanctum::actingAs($employeeUser);
        $this->getJson('/api/my-forms?required_on_login=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $assignmentId);

        $fieldId = $template->normalizedSchema()['fields'][0]['id'];
        $this->putJson("/api/my-forms/{$assignmentId}", [
            'answers' => [$fieldId => 'Great week'],
            'submit' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_submitted', true);

        $this->getJson('/api/my-forms?required_on_login=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_user_without_employee_record_does_not_receive_assignments(): void
    {
        $admin = $this->formAdmin();
        Sanctum::actingAs($admin);
        $template = $this->feedbackTemplate();
        $this->postJson("/api/form-templates/{$template->id}/assignments", [
            'audience_type' => FormAssignment::AUDIENCE_COMPANY,
            'is_required' => true,
            'show_on_login' => true,
        ])->assertCreated();

        Sanctum::actingAs($admin);
        $this->getJson('/api/my-forms')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_employee_group_audience_limits_who_can_submit(): void
    {
        $admin = $this->formAdmin();
        $included = $this->employeeUser('in@example.com', 'In', 'Group');
        $excluded = $this->employeeUser('out@example.com', 'Out', 'Group');

        $group = EmployeeGroup::query()->create([
            'name' => 'Kitchen',
            'isActive' => true,
        ]);
        EmployeeGroupMember::query()->create([
            'employeeGroupId' => $group->id,
            'employeeId' => $included->employee->id,
            'startDate' => now()->subDay()->toDateString(),
            'endDate' => null,
        ]);

        Sanctum::actingAs($admin);
        $template = $this->feedbackTemplate();
        $assignmentId = $this->postJson("/api/form-templates/{$template->id}/assignments", [
            'audience_type' => FormAssignment::AUDIENCE_EMPLOYEE_GROUPS,
            'employee_group_ids' => [$group->id],
            'is_required' => true,
            'show_on_login' => true,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($included);
        $this->getJson('/api/my-forms')->assertOk()->assertJsonPath('data.0.id', $assignmentId);

        Sanctum::actingAs($excluded);
        $this->getJson('/api/my-forms')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/my-forms/{$assignmentId}", [
            'answers' => [$template->normalizedSchema()['fields'][0]['id'] => 'Nope'],
        ])->assertForbidden();
    }

    public function test_submitting_starts_the_form_review_pipeline(): void
    {
        $admin = $this->formAdmin();
        Sanctum::actingAs($admin);
        $template = $this->feedbackTemplate();
        $pipeline = PipelineTemplate::query()->where('key', 'form_review')->first()
            ?? PipelineTemplate::query()->create([
                'key' => 'form_review',
                'name' => 'Form review',
                'subject_type' => PipelineEngine::SUBJECT_FORM_RESPONSE,
                'is_active' => true,
                'completion_status' => 'approved',
                'rejection_status' => 'rejected',
            ]);
        if ($pipeline->steps()->doesntExist()) {
            $pipeline->steps()->create([
                'key' => 'supervisor',
                'label' => 'Supervisor review',
                'sort_order' => 1,
                'assignee_type' => 'supervisor',
                'actions' => ['approve', 'reject'],
            ]);
        }

        $assignmentId = $this->postJson("/api/form-templates/{$template->id}/assignments", [
            'audience_type' => FormAssignment::AUDIENCE_COMPANY,
            'pipeline_template_id' => $pipeline->id,
            'is_required' => false,
            'show_on_login' => false,
        ])->assertCreated()->json('data.id');

        $employeeUser = $this->employeeUser('review@example.com', 'Rev', 'Iew');
        Sanctum::actingAs($employeeUser);
        $responseId = $this->putJson("/api/my-forms/{$assignmentId}", [
            'answers' => [$template->normalizedSchema()['fields'][0]['id'] => 'Ready'],
            'submit' => true,
        ])->assertOk()->json('data.id');

        $this->assertTrue(
            PipelineInstance::query()
                ->where('subject_type', PipelineEngine::SUBJECT_FORM_RESPONSE)
                ->where('subject_id', $responseId)
                ->exists(),
        );
    }

    private function feedbackTemplate(): FormTemplate
    {
        return FormTemplate::query()->create([
            'name' => 'Pulse check',
            'purpose' => FormTemplate::PURPOSE_FEEDBACK,
            'is_active' => true,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'q1',
                        'type' => 'textarea',
                        'label' => 'How was your week?',
                        'required' => true,
                    ],
                ],
            ],
        ]);
    }

    private function employeeUser(string $email, string $first, string $last): User
    {
        $lookups = $this->employeeLookups();
        $employee = EmployeePersonSync::create([
            'firstName' => $first,
            'lastName' => $last,
            'email' => $email,
            'phone' => '615-0003',
            'birthdate' => '1990-01-01',
            'address1' => '1 Main Street',
            'localityId' => $lookups['locality']->id,
            'genderId' => $lookups['gender']->id,
            'socialSecurityNumber' => substr(preg_replace('/\D/', '', md5($email)) ?? '10000333', 0, 8),
            'paymentMethodId' => $lookups['payment']->id,
            'code' => substr(md5($email), 0, 6),
        ]);

        return $employee->fresh('user')->user;
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
        $district = \App\Models\District::query()->firstOrCreate(
            ['name' => 'Cayo'],
            ['countryId' => $country->id],
        );
        $locality = Locality::query()->firstOrCreate(
            ['name' => 'San Ignacio'],
            ['districtId' => $district->id],
        );
        $gender = Gender::query()->firstOrCreate(['name' => 'Female']);
        $payment = PaymentMethod::query()->firstOrCreate(['name' => 'Bank transfer']);

        return compact('locality', 'gender', 'payment');
    }

    private function formAdmin(): User
    {
        $permissions = [];
        foreach (['view-vacancies', 'vacancies-crud', 'form-templates-crud'] as $name) {
            $permissions[] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $admin = User::query()->create([
            'name' => 'Admin',
            'username' => 'form-admin',
            'email' => 'form-admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $admin->givePermissionTo($permissions);

        return $admin;
    }
}
