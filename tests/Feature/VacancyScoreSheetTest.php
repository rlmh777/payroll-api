<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\MailSetting;
use App\Models\Permission;
use App\Models\User;
use App\Models\Country;
use App\Models\District;
use App\Models\Gender;
use App\Models\Locality;
use App\Models\PaymentMethod;
use App\Modules\Hr\Models\CandidateStage;
use App\Modules\Hr\Models\FormTemplate;
use App\Modules\Hr\Services\EmployeePersonSync;
use App\Modules\Hr\Models\Vacancy;
use App\Modules\Hr\Models\VacancyApplication;
use App\Modules\Hr\Models\VacancyPanelMember;
use App\Modules\Hr\Models\VacancyStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VacancyScoreSheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_create_form_template_and_assign_it_to_a_vacancy(): void
    {
        Sanctum::actingAs($this->vacancyAdmin());
        $template = FormTemplate::query()->where('name', 'Interview score sheet')->firstOrFail();

        $created = $this->postJson('/api/form-templates', [
            'name' => 'Chef panel',
            'purpose' => 'score_sheet',
            'schema' => [
                'fields' => [
                    ['type' => 'score', 'label' => 'Taste', 'min' => 0, 'max' => 5, 'weight' => 2, 'required' => true],
                    ['type' => 'textarea', 'label' => 'Notes'],
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Chef panel')
            ->assertJsonPath('data.max_score', 10);

        $id = $created->json('data.id');
        $stage = VacancyStage::query()->where('is_default', true)->firstOrFail();

        $this->postJson('/api/vacancies', [
            'title' => 'Head chef',
            'positions' => 1,
            'vacancy_stage_id' => $stage->id,
            'form_template_id' => $id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.form_template_id', $id)
            ->assertJsonPath('data.form_template.name', 'Chef panel');

        $this->assertNotNull($template->id);
    }

    public function test_panel_member_can_login_with_invite_code_and_submit_a_score(): void
    {
        Mail::fake();
        MailSetting::query()->create([
            'enabled' => true,
            'mailer' => 'log',
            'from_address' => 'hr@example.com',
            'from_name' => 'HR',
        ]);

        $admin = $this->vacancyAdmin();
        Sanctum::actingAs($admin);
        $vacancy = $this->vacancyWithScoreSheet();
        $application = $this->applicationFor($vacancy);

        $this->postJson("/api/vacancies/{$vacancy->id}/panel-members", [
            'name' => 'Jordan Panel',
            'email' => 'jordan@example.com',
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'jordan@example.com');

        $member = VacancyPanelMember::query()->where('email', 'jordan@example.com')->firstOrFail();
        $this->assertNotNull($member->invite_code_hash);

        $code = null;
        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$code) {
            if (! $mail->hasTo('jordan@example.com') || ! str_contains($mail->emailSubject, 'Head chef')) {
                return false;
            }
            if (preg_match('/>([A-Z0-9]{8})</', $mail->htmlBody, $matches)) {
                $code = $matches[1];
            }

            return true;
        });
        $this->assertNotEmpty($code);

        $this->postJson('/api/login/invite-code', [
            'email' => 'jordan@example.com',
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'jordan@example.com');

        $panel = User::query()->where('email', 'jordan@example.com')->firstOrFail();
        Sanctum::actingAs($panel);

        $answers = [];
        foreach ($vacancy->formTemplate->normalizedSchema()['fields'] as $field) {
            if ($field['type'] === 'section') {
                continue;
            }
            $answers[$field['id']] = match ($field['type']) {
                'score' => 8,
                'select', 'radio' => $field['options'][1]['value'] ?? $field['options'][0]['value'],
                'textarea', 'text' => 'Strong kitchen presence.',
                default => null,
            };
        }

        $this->putJson("/api/panel/vacancies/{$vacancy->id}/applications/{$application->id}/score", [
            'answers' => $answers,
            'comments' => 'Strong kitchen presence.',
            'submit' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_submitted', true)
            ->assertJsonPath('data.total_score', 24);

        Sanctum::actingAs($admin);
        $this->getJson("/api/vacancies/{$vacancy->id}/applications/{$application->id}/scores")
            ->assertOk()
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('data.0.scorer.email', 'jordan@example.com');
    }

    public function test_unique_link_needs_the_email_code_and_is_invalidated_when_removed(): void
    {
        Mail::fake();
        MailSetting::query()->create([
            'enabled' => true,
            'mailer' => 'log',
            'from_address' => 'hr@example.com',
            'from_name' => 'HR',
        ]);

        $admin = $this->vacancyAdmin();
        Sanctum::actingAs($admin);
        $vacancy = $this->vacancyWithScoreSheet();
        $application = $this->applicationFor($vacancy);

        $this->postJson("/api/vacancies/{$vacancy->id}/panel-members", [
            'member_type' => 'external',
            'name' => 'Jordan Panel',
            'email' => 'jordan@example.com',
        ])->assertCreated()
            ->assertJsonPath('data.member_type', 'external');

        $member = VacancyPanelMember::query()->where('email', 'jordan@example.com')->firstOrFail();
        $this->assertNotEmpty($member->access_token);

        $code = null;
        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$code, $member) {
            if (! str_contains($mail->htmlBody, '/panel/access/'.$member->access_token)) {
                return false;
            }
            if (preg_match('/>([A-Z0-9]{8})</', $mail->htmlBody, $matches)) {
                $code = $matches[1];
            }

            return true;
        });

        $this->getJson("/api/panel/access/{$member->access_token}")
            ->assertOk()
            ->assertJsonPath('data.email', 'jordan@example.com');

        $this->postJson("/api/panel/access/{$member->access_token}/verify", [
            'code' => 'WRONGCODE',
        ])->assertStatus(400);

        $this->postJson("/api/panel/access/{$member->access_token}/verify", [
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('user.email', 'jordan@example.com');

        $panel = User::query()->where('email', 'jordan@example.com')->firstOrFail();
        Sanctum::actingAs($panel);
        $this->postJson("/api/panel/vacancies/{$vacancy->id}/recommendation", [
            'application_id' => $application->id,
        ])->assertOk()
            ->assertJsonPath('data.recommended_application_id', $application->id);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/vacancies/{$vacancy->id}/panel-members/{$member->id}")
            ->assertOk();

        $member->refresh();
        $this->assertNull($member->access_token);
        $this->assertSame(VacancyPanelMember::STATUS_REVOKED, $member->status);

        $this->getJson('/api/panel/access/'.str_repeat('a', 64))->assertStatus(410);

        Sanctum::actingAs($panel);
        $this->getJson("/api/panel/vacancies/{$vacancy->id}/applications/{$application->id}/score")
            ->assertForbidden();
    }

    public function test_submitted_score_sheet_cannot_be_opened_or_changed(): void
    {
        $admin = $this->vacancyAdmin();
        Sanctum::actingAs($admin);
        $vacancy = $this->vacancyWithScoreSheet();
        $application = $this->applicationFor($vacancy);
        $invited = app(\App\Modules\Hr\Services\PanelInviteService::class)->invite(
            $vacancy,
            'Jordan Panel',
            'jordan@example.com',
            false,
        );
        $panel = app(\App\Modules\Hr\Services\PanelInviteService::class)->activate($invited['member']);

        Sanctum::actingAs($panel);
        $answers = $this->answersFor($vacancy);
        $this->putJson("/api/panel/vacancies/{$vacancy->id}/applications/{$application->id}/score", [
            'answers' => $answers,
            'comments' => 'Strong kitchen presence.',
            'submit' => true,
        ])->assertOk();

        $this->getJson("/api/panel/vacancies/{$vacancy->id}/applications/{$application->id}/score")
            ->assertForbidden();
        $this->putJson("/api/panel/vacancies/{$vacancy->id}/applications/{$application->id}/score", [
            'answers' => $answers,
            'comments' => 'Changed my mind.',
            'submit' => true,
        ])->assertStatus(422);

        Sanctum::actingAs($admin);
        $this->getJson("/api/vacancies/{$vacancy->id}/interview")
            ->assertOk()
            ->assertJsonPath('candidates.0.summary.count', 1)
            ->assertJsonPath('candidates.0.summary.total', 24)
            ->assertJsonPath('candidates.0.scores.0.comments', 'Strong kitchen presence.');
    }

    public function test_internal_panel_member_uses_the_employee_email(): void
    {
        Mail::fake();
        $admin = $this->vacancyAdmin();
        Sanctum::actingAs($admin);
        $vacancy = $this->vacancyWithScoreSheet();

        $country = Country::query()->firstOrCreate(
            ['code1' => 'BZ'],
            ['name' => 'Belize', 'code2' => 'BLZ', 'nationalityName' => 'Belizean'],
        );
        $district = District::query()->firstOrCreate(
            ['name' => 'Cayo'],
            ['countryId' => $country->id],
        );
        $locality = Locality::query()->firstOrCreate(
            ['name' => 'San Ignacio'],
            ['districtId' => $district->id],
        );
        $gender = Gender::query()->firstOrCreate(['name' => 'Female']);
        $payment = PaymentMethod::query()->firstOrCreate(['name' => 'Bank transfer']);
        $employee = EmployeePersonSync::create([
            'firstName' => 'Alicia',
            'lastName' => 'Reed',
            'email' => 'alicia@example.com',
            'phone' => '615-0003',
            'birthdate' => '1990-01-15',
            'address1' => '1 Main Street',
            'localityId' => $locality->id,
            'genderId' => $gender->id,
            'socialSecurityNumber' => '10000333',
            'paymentMethodId' => $payment->id,
            'code' => 'ALICIA',
        ]);

        $this->postJson("/api/vacancies/{$vacancy->id}/panel-members", [
            'member_type' => 'internal',
            'employee_id' => $employee->id,
        ])->assertCreated()
            ->assertJsonPath('data.email', 'alicia@example.com')
            ->assertJsonPath('data.member_type', 'internal')
            ->assertJsonPath('data.name', 'Alicia Reed');

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('alicia@example.com'));
    }

    public function test_invalid_invite_code_is_rejected(): void
    {
        $this->postJson('/api/login/invite-code', [
            'email' => 'nobody@example.com',
            'code' => 'ABCDEFGH',
        ])->assertStatus(400);
    }

    private function vacancyWithScoreSheet(): Vacancy
    {
        $template = FormTemplate::query()->where('name', 'Interview score sheet')->firstOrFail();
        $stage = VacancyStage::query()->where('is_default', true)->firstOrFail();

        return Vacancy::query()->create([
            'title' => 'Head chef',
            'positions' => 1,
            'require_resume' => false,
            'advertise_internal' => true,
            'advertise_public' => false,
            'vacancy_stage_id' => $stage->id,
            'form_template_id' => $template->id,
            'sort_order' => 1,
        ])->load('formTemplate');
    }

    private function applicationFor(Vacancy $vacancy): VacancyApplication
    {
        $applicant = \App\Modules\Hr\Models\Applicant::query()->create([
            'first_name' => 'Maya',
            'last_name' => 'Chan',
            'email' => 'maya@example.com',
        ]);

        $stage = CandidateStage::query()->where('is_shortlist', true)->firstOrFail();

        return VacancyApplication::query()->create([
            'vacancy_id' => $vacancy->id,
            'applicant_id' => $applicant->id,
            'candidate_stage_id' => $stage->id,
            'source' => VacancyApplication::SOURCE_MANUAL,
            'status' => VacancyApplication::STATUS_RECEIVED,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function answersFor(Vacancy $vacancy): array
    {
        $answers = [];
        foreach ($vacancy->formTemplate->normalizedSchema()['fields'] as $field) {
            if ($field['type'] === 'section') {
                continue;
            }
            $answers[$field['id']] = match ($field['type']) {
                'score' => 8,
                'select', 'radio' => $field['options'][1]['value'] ?? $field['options'][0]['value'],
                'textarea', 'text' => 'Strong kitchen presence.',
                default => null,
            };
        }

        return $answers;
    }

    private function vacancyAdmin(): User
    {
        $permissions = [];
        foreach (['view-vacancies', 'vacancies-crud', 'score-vacancies', 'form-templates-crud'] as $name) {
            $permissions[] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $admin = User::query()->create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $admin->givePermissionTo($permissions);

        return $admin;
    }
}
