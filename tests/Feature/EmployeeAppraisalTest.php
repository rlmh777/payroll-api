<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Gender;
use App\Models\HrSetting;
use App\Models\Locality;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\User;
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
