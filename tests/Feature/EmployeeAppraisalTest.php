<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ContractType;
use App\Models\Country;
use App\Models\Department;
use App\Models\EmploymentDetail;
use App\Models\Gender;
use App\Models\HrSetting;
use App\Models\Locality;
use App\Models\PayPeriodGroup;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\User;
use App\Models\Worksite;
use App\Modules\Hr\Services\Appraisal\AppraisalScheduleService;
use App\Modules\Hr\Models\EmployeeAppraisal;
use App\Modules\Hr\Models\FormTemplate;
use App\Modules\Hr\Services\EmployeePersonSync;
use App\Support\ConfiguredMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeAppraisalTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminder_schedule_defaults_and_can_be_updated(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['view-hr-settings']));

        $this->getJson('/api/hr-settings')
            ->assertOk()
            ->assertJsonPath('appraisalReminderEnabled', true)
            ->assertJsonPath('appraisalReminderIntervalValue', 2)
            ->assertJsonPath('appraisalReminderIntervalUnit', 'days');

        $this->putJson('/api/hr-settings', [
            'appraisalReminderEnabled' => true,
            'appraisalReminderIntervalValue' => 1,
            'appraisalReminderIntervalUnit' => 'weeks',
        ])
            ->assertOk()
            ->assertJsonPath('data.appraisalReminderIntervalValue', 1)
            ->assertJsonPath('data.appraisalReminderIntervalUnit', 'weeks');
    }

    public function test_appraisal_moves_through_supervisor_hr_decision_and_accounts(): void
    {
        $mail = $this->mock(ConfiguredMail::class);
        $mail->shouldReceive('isReady')->andReturn(true);
        $mail->shouldReceive('sendHtml')->andReturnNull();

        $hr = $this->userWithPermissions(['employees-crud', 'view-appraisals', 'appraisals-crud']);
        $supervisor = $this->employeeUser('supervisor@example.com', 'Sam', 'Boss');
        $subject = $this->employeeUser('alex@example.com', 'Alex', 'River');
        $accounts = $this->userWithPermissions(['pay-employees-crud']);

        $subjectEmployee = $subject->fresh()->employee;
        $subjectEmployee->supervisorId = $supervisor->employee->id;
        $subjectEmployee->save();

        $formId = FormTemplate::query()->where('purpose', FormTemplate::PURPOSE_APPRAISAL)->value('id');
        $this->assertNotNull($formId);

        Sanctum::actingAs($hr);
        $created = $this->postJson('/api/appraisals', [
            'employee_id' => $subjectEmployee->id,
            'period_label' => '2026 annual',
            'form_template_id' => $formId,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.pipeline.currentStep.key', 'supervisor');

        $id = $created->json('data.id');

        Sanctum::actingAs($supervisor);
        $this->getJson('/api/appraisals/inbox')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->postJson("/api/appraisals/{$id}/actions", [
            'action' => 'approve',
            'submit_form' => true,
            'next_appraisal_date' => now()->addYear()->toDateString(),
            'answers' => [
                'overall_performance' => 4,
                'strengths' => 'Reliable delivery',
                'development' => 'Delegation',
                'goals' => 'Lead a project',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.pipeline.currentStep.key', 'hr')
            ->assertJsonPath('data.is_hr_step', true);

        Sanctum::actingAs($hr);
        $this->postJson("/api/appraisals/{$id}/actions", ['action' => 'approve'])
            ->assertStatus(422);

        $this->postJson("/api/appraisals/{$id}/actions", [
            'action' => 'approve',
            'hr_renew_contract' => true,
            'hr_increment_approved' => true,
            'hr_increment_type' => 'percent',
            'hr_increment_value' => 5,
            'note' => 'Strong year. Renew and 5% increment.',
        ])
            ->assertOk()
            ->assertJsonPath('data.pipeline.currentStep.key', 'accounts')
            ->assertJsonPath('data.hr_renew_contract', true)
            ->assertJsonPath('data.hr_increment_approved', true);

        Sanctum::actingAs($accounts);
        $this->postJson("/api/appraisals/{$id}/actions", [
            'action' => 'approve',
            'accounts_notes' => 'Increment loaded for next cycle.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.accounts_notes', 'Increment loaded for next cycle.');
    }

    public function test_supervisor_starts_an_appraisal_from_the_contract_form_and_sets_the_next_date(): void
    {
        $mail = $this->mock(ConfiguredMail::class);
        $mail->shouldReceive('isReady')->andReturn(false);

        $supervisor = $this->employeeUser('lead@example.com', 'Lea', 'Der');
        $subject = $this->employeeUser('report@example.com', 'Re', 'Port');
        $stranger = $this->employeeUser('other.report@example.com', 'Ot', 'Her');
        $subject->fresh()->employee->update(['supervisorId' => $supervisor->employee->id]);

        $formId = FormTemplate::query()->where('purpose', FormTemplate::PURPOSE_APPRAISAL)->value('id');
        $otherForm = FormTemplate::query()->create([
            'name' => 'Other appraisal',
            'purpose' => FormTemplate::PURPOSE_APPRAISAL,
            'schema' => ['fields' => []],
            'is_active' => true,
        ]);
        $contract = $this->createContract($subject->employee->id, $formId, now()->addMonths(6)->toDateString());

        Sanctum::actingAs($supervisor);
        $this->postJson('/api/appraisals', [
            'employee_id' => $stranger->employee->id,
            'period_label' => 'Not my report',
            'form_template_id' => $otherForm->id,
        ])->assertForbidden();

        $this->postJson('/api/appraisals', [
            'employee_id' => $subject->employee->id,
            'period_label' => '2026 review',
            'form_template_id' => $otherForm->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.form_template.id', $formId)
            ->assertJsonPath('data.pipeline.currentStep.key', 'supervisor');

        $id = EmployeeAppraisal::query()->where('employee_id', $subject->employee->id)->value('id');
        $next = now()->addMonths(12)->toDateString();
        $this->postJson("/api/appraisals/{$id}/actions", [
            'action' => 'approve',
            'next_appraisal_date' => $next,
            'answers' => [
                'overall_performance' => 4,
                'strengths' => 'Reliable delivery',
                'development' => 'Delegation',
                'goals' => 'Lead a project',
            ],
        ])->assertOk();

        $this->assertSame($next, $contract->fresh()->appraisalDate->toDateString());
    }

    public function test_due_appraisals_start_three_months_before_the_contract_date(): void
    {
        $mail = $this->mock(ConfiguredMail::class);
        $mail->shouldReceive('isReady')->andReturn(false);

        $soon = $this->employeeUser('soon@example.com', 'So', 'On');
        $later = $this->employeeUser('later@example.com', 'La', 'Ter');
        $formId = FormTemplate::query()->where('purpose', FormTemplate::PURPOSE_APPRAISAL)->value('id');

        $this->createContract($soon->employee->id, $formId, now()->addMonths(3)->toDateString());
        $this->createContract($later->employee->id, $formId, now()->addMonths(3)->addDay()->toDateString());

        $stats = app(AppraisalScheduleService::class)->startDue();

        $this->assertSame(1, $stats['started']);
        $this->assertTrue(
            EmployeeAppraisal::query()->where('employee_id', $soon->employee->id)->where('status', 'pending')->exists()
        );
        $this->assertFalse(
            EmployeeAppraisal::query()->where('employee_id', $later->employee->id)->exists()
        );
    }

    public function test_reminder_command_emails_stale_pending_steps(): void
    {
        $mail = $this->mock(ConfiguredMail::class);
        $mail->shouldReceive('isReady')->andReturn(true);
        $mail->shouldReceive('sendHtml')->andReturnNull();

        $hr = $this->userWithPermissions(['employees-crud', 'appraisals-crud']);
        $supervisor = $this->employeeUser('sup2@example.com', 'Sue', 'Pervis');
        $subject = $this->employeeUser('lee@example.com', 'Lee', 'Ng');
        $subject->fresh()->employee->update(['supervisorId' => $supervisor->employee->id]);

        Sanctum::actingAs($hr);
        $id = $this->postJson('/api/appraisals', [
            'employee_id' => $subject->employee->id,
            'period_label' => 'Q4',
        ])->assertCreated()->json('data.id');

        EmployeeAppraisal::query()->whereKey($id)->update([
            'last_notified_at' => now()->subDays(5),
            'last_reminded_at' => now()->subDays(5),
        ]);

        $setting = HrSetting::current();
        $setting->update([
            'appraisal_reminder_enabled' => true,
            'appraisal_reminder_interval_value' => 2,
            'appraisal_reminder_interval_unit' => 'days',
        ]);
        HrSetting::clearCurrentCache();

        $this->artisan('hr:send-appraisal-reminders')->assertSuccessful();
    }

    private function createContract(string $employeeId, string $formId, ?string $appraisalDate): EmploymentDetail
    {
        $lookups = $this->employeeLookups();
        $department = Department::query()->create(['name' => 'Ops '.uniqid()]);
        $worksite = Worksite::query()->create([
            'name' => 'Site '.uniqid(),
            'address1' => '1 Main Street',
            'localityId' => $lookups['locality']->id,
        ]);
        $account = Account::query()->create([
            'name' => 'Wages '.uniqid(),
            'description' => 'Wages',
            'code1' => substr(uniqid(), 0, 8),
        ]);
        $contractType = ContractType::query()->firstOrCreate(['name' => 'Fixed term']);
        $payGroup = PayPeriodGroup::query()->create([
            'name' => 'Monthly '.uniqid(),
            'status' => 'active',
        ]);

        return EmploymentDetail::query()->create([
            'employeeId' => $employeeId,
            'startDate' => now()->subYear()->toDateString(),
            'endDate' => now()->addYear()->toDateString(),
            'isActive' => true,
            'benefits' => '',
            'employmentPolicies' => '',
            'accountId' => $account->id,
            'contractTypeId' => $contractType->id,
            'departmentId' => $department->id,
            'worksiteId' => $worksite->id,
            'defaultPayPeriodGroupId' => $payGroup->id,
            'appraisalDate' => $appraisalDate,
            'appraisalFormTemplateId' => $formId,
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
}
