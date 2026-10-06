<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\District;
use App\Models\Gender;
use App\Models\JobTitle;
use App\Models\Locality;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\User;
use App\Modules\Hr\Models\Applicant;
use App\Modules\Hr\Models\CandidateStage;
use App\Modules\Hr\Models\Vacancy;
use App\Modules\Hr\Models\VacancyApplication;
use App\Modules\Hr\Models\VacancyStage;
use App\Modules\Hr\Services\EmployeePersonSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VacancyApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_public_application_increments_board_count(): void
    {
        $vacancy = $this->publishedVacancy(requireResume: false);

        $this->post('/api/careers/vacancies/'.$vacancy->id.'/applications', [
            'first_name' => 'Maya',
            'last_name' => 'Chan',
            'email' => 'maya@example.com',
            'phone' => '615-0099',
            'cover_letter' => 'I would like to apply.',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.applicant.email', 'maya@example.com');

        $admin = $this->vacancyAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/vacancies/board')
            ->assertOk()
            ->assertJsonFragment(['title' => $vacancy->title, 'applications_count' => 1]);

        $this->getJson("/api/vacancies/{$vacancy->id}/applications")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.applicant.first_name', 'Maya');
    }

    public function test_resume_required_when_vacancy_asks_for_it(): void
    {
        $vacancy = $this->publishedVacancy(requireResume: true);

        $this->postJson("/api/careers/vacancies/{$vacancy->id}/applications", [
            'first_name' => 'Maya',
            'last_name' => 'Chan',
            'email' => 'maya@example.com',
        ])->assertStatus(422);

        $file = UploadedFile::fake()->create('maya.pdf', 20, 'application/pdf');
        $this->post("/api/careers/vacancies/{$vacancy->id}/applications", [
            'first_name' => 'Maya',
            'last_name' => 'Chan',
            'email' => 'maya@example.com',
            'resume' => $file,
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_converting_applicant_updates_existing_employee(): void
    {
        $lookups = $this->employeeLookups();
        $existing = EmployeePersonSync::create([
            'firstName' => 'Maya',
            'lastName' => 'Old',
            'email' => 'maya@example.com',
            'phone' => '615-0001',
            'birthdate' => '1991-02-02',
            'address1' => 'Old Street',
            'localityId' => $lookups['locality']->id,
            'genderId' => $lookups['gender']->id,
            'socialSecurityNumber' => '111222333',
            'paymentMethodId' => $lookups['payment']->id,
            'code' => 'MAYOLD910202ABCD',
        ]);

        $vacancy = $this->publishedVacancy(requireResume: false);
        $this->postJson("/api/careers/vacancies/{$vacancy->id}/applications", [
            'first_name' => 'Maya',
            'last_name' => 'Chan',
            'email' => 'maya@example.com',
            'phone' => '615-0099',
        ])->assertCreated();

        $application = VacancyApplication::query()->firstOrFail();
        $admin = $this->vacancyAdmin(withEmployeeCrud: true);
        Sanctum::actingAs($admin);

        $this->postJson("/api/vacancies/{$vacancy->id}/applications/{$application->id}/convert-to-employee")
            ->assertOk()
            ->assertJsonPath('action', 'updated')
            ->assertJsonPath('employee.id', $existing->id)
            ->assertJsonPath('employee.lastName', 'Chan');

        $this->assertSame($existing->id, Applicant::query()->first()->employee_id);
        $this->assertSame('Chan', $existing->fresh()->person->lastName);
        $this->assertSame('615-0099', $existing->fresh()->person->phone);
        $this->assertSame(VacancyApplication::STATUS_CONVERTED, $application->fresh()->status);
    }

    public function test_converting_applicant_creates_employee_when_none_exists(): void
    {
        $lookups = $this->employeeLookups();
        $vacancy = $this->publishedVacancy(requireResume: false);
        $this->postJson("/api/careers/vacancies/{$vacancy->id}/applications", [
            'first_name' => 'Luis',
            'last_name' => 'Herrera',
            'email' => 'luis@example.com',
        ])->assertCreated();

        $application = VacancyApplication::query()->firstOrFail();
        $admin = $this->vacancyAdmin(withEmployeeCrud: true);
        Sanctum::actingAs($admin);

        $this->postJson("/api/vacancies/{$vacancy->id}/applications/{$application->id}/convert-to-employee", [
            'birthdate' => '1988-05-05',
            'address1' => '12 River Road',
            'locality_id' => $lookups['locality']->id,
            'gender_id' => $lookups['gender']->id,
            'social_security_number' => '999888777',
            'paymentMethodId' => $lookups['payment']->id,
        ])
            ->assertOk()
            ->assertJsonPath('action', 'created')
            ->assertJsonPath('employee.firstName', 'Luis');

        $this->assertNotNull(Applicant::query()->first()->employee_id);
        $hired = CandidateStage::query()->where('is_hired', true)->first();
        $this->assertNotNull($hired);
        $this->assertSame($hired->id, VacancyApplication::query()->first()->candidate_stage_id);
    }

    public function test_candidate_pipeline_is_separate_from_vacancy_stages(): void
    {
        $this->assertSame(
            [
                'Application received',
                'Short listed',
                'In progress',
                'Job offer',
                'Preboarding',
                'Hired',
                'Rejected',
            ],
            CandidateStage::query()->orderBy('sort_order')->pluck('name')->all(),
        );

        $vacancy = $this->publishedVacancy(requireResume: false);
        $this->postJson("/api/careers/vacancies/{$vacancy->id}/applications", [
            'first_name' => 'Maya',
            'last_name' => 'Chan',
            'email' => 'maya@example.com',
        ])->assertCreated();

        $application = VacancyApplication::query()->firstOrFail();
        $received = CandidateStage::query()->where('is_default', true)->firstOrFail();
        $shortlisted = CandidateStage::query()->where('name', 'Short listed')->firstOrFail();
        $this->assertSame($received->id, $application->candidate_stage_id);

        $admin = $this->vacancyAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/vacancy-applications/board')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Application received')
            ->assertJsonPath('data.0.applications.0.applicant.first_name', 'Maya');

        $this->postJson("/api/vacancy-applications/{$application->id}/move", [
            'candidate_stage_id' => $shortlisted->id,
            'ordered_ids' => [$application->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.candidate_stage_id', $shortlisted->id);

        $this->getJson('/api/vacancy-applications/board?search=maya')
            ->assertOk()
            ->assertJsonPath('data.1.applications.0.applicant.email', 'maya@example.com');

        $this->getJson('/api/vacancy-applications/board?search=nobody')
            ->assertOk()
            ->assertJsonPath('data.1.applications', []);

        $this->postJson('/api/candidate-stages', [
            'name' => 'Background check',
            'color' => '#0ea5e9',
        ])->assertCreated()->assertJsonPath('data.name', 'Background check');
    }

    private function publishedVacancy(bool $requireResume): Vacancy
    {
        $jobTitle = JobTitle::query()->create(['name' => 'Front Desk '.$requireResume]);
        $published = VacancyStage::query()->where('name', 'Published')->firstOrFail();

        return Vacancy::query()->create([
            'title' => 'Guest services',
            'job_title_id' => $jobTitle->id,
            'positions' => 1,
            'require_resume' => $requireResume,
            'advertise_internal' => true,
            'advertise_public' => true,
            'vacancy_stage_id' => $published->id,
            'sort_order' => 1,
        ]);
    }

    /**
     * @return array{locality: Locality, gender: Gender, payment: PaymentMethod}
     */
    private function employeeLookups(): array
    {
        $country = Country::query()->create([
            'name' => 'Belize',
            'code1' => 'BZ',
            'code2' => 'BLZ',
            'nationalityName' => 'Belizean',
        ]);
        $district = District::query()->create([
            'name' => 'Cayo',
            'countryId' => $country->id,
        ]);
        $locality = Locality::query()->create([
            'name' => 'San Ignacio',
            'districtId' => $district->id,
        ]);
        $gender = Gender::query()->create(['name' => 'Female']);
        $payment = PaymentMethod::query()->create(['name' => 'Bank transfer']);

        return compact('locality', 'gender', 'payment');
    }

    private function vacancyAdmin(bool $withEmployeeCrud = false): User
    {
        $permissions = [
            Permission::query()->firstOrCreate(['name' => 'view-vacancies', 'guard_name' => 'web']),
            Permission::query()->firstOrCreate(['name' => 'vacancies-crud', 'guard_name' => 'web']),
        ];
        if ($withEmployeeCrud) {
            $permissions[] = Permission::query()->firstOrCreate([
                'name' => 'employees-crud',
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
