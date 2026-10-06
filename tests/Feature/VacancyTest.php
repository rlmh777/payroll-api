<?php

namespace Tests\Feature;

use App\Models\JobTitle;
use App\Models\Permission;
use App\Models\User;
use App\Modules\Hr\Models\Vacancy;
use App\Modules\Hr\Models\VacancyStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VacancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_stages_are_seeded(): void
    {
        $this->assertSame(
            ['New', 'Draft', 'Published', 'Closed'],
            VacancyStage::query()->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertTrue(VacancyStage::query()->where('name', 'New')->value('is_default'));
        $this->assertTrue(VacancyStage::query()->where('name', 'Published')->value('lists_public'));
    }

    public function test_public_careers_lists_only_published_public_vacancies(): void
    {
        $jobTitle = JobTitle::query()->create([
            'name' => 'Sous Chef',
            'notes' => 'Prep and line work.',
        ]);
        $new = VacancyStage::query()->where('name', 'New')->firstOrFail();
        $published = VacancyStage::query()->where('name', 'Published')->firstOrFail();

        Vacancy::query()->create([
            'title' => 'Internal only cook',
            'job_title_id' => $jobTitle->id,
            'positions' => 1,
            'require_resume' => true,
            'description' => 'Prep and line work.',
            'advertise_internal' => true,
            'advertise_public' => false,
            'vacancy_stage_id' => $published->id,
            'sort_order' => 1,
        ]);

        $public = Vacancy::query()->create([
            'title' => 'Restaurant sous chef',
            'job_title_id' => $jobTitle->id,
            'positions' => 2,
            'require_resume' => true,
            'description' => 'Prep and line work.',
            'advertise_internal' => true,
            'advertise_public' => true,
            'vacancy_stage_id' => $published->id,
            'sort_order' => 2,
        ]);

        Vacancy::query()->create([
            'title' => 'Draft public cook',
            'job_title_id' => $jobTitle->id,
            'positions' => 1,
            'require_resume' => false,
            'advertise_internal' => false,
            'advertise_public' => true,
            'vacancy_stage_id' => $new->id,
            'sort_order' => 1,
        ]);

        $this->getJson('/api/careers/vacancies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $public->id)
            ->assertJsonPath('data.0.title', 'Restaurant sous chef')
            ->assertJsonMissingPath('data.0.hiring_manager_id');

        $this->getJson("/api/careers/vacancies/{$public->id}")
            ->assertOk()
            ->assertJsonPath('title', 'Restaurant sous chef');
    }

    public function test_admin_can_create_vacancy_and_move_between_stages(): void
    {
        $admin = $this->vacancyAdmin();
        Sanctum::actingAs($admin);

        $jobTitle = JobTitle::query()->create([
            'name' => 'Front Desk',
            'notes' => 'Guest check-in and reservations.',
        ]);
        $new = VacancyStage::query()->where('name', 'New')->firstOrFail();
        $published = VacancyStage::query()->where('name', 'Published')->firstOrFail();

        $created = $this->postJson('/api/vacancies', [
            'title' => 'Night auditor',
            'job_title_id' => $jobTitle->id,
            'positions' => 1,
            'require_resume' => true,
            'advertise_internal' => true,
            'advertise_public' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Night auditor')
            ->assertJsonPath('data.vacancy_stage_id', $new->id)
            ->assertJsonPath('data.description', 'Guest check-in and reservations.');

        $id = $created->json('data.id');

        $this->postJson("/api/vacancies/{$id}/move", [
            'vacancy_stage_id' => $published->id,
            'ordered_ids' => [$id],
        ])
            ->assertOk()
            ->assertJsonPath('data.vacancy_stage_id', $published->id);

        $this->getJson('/api/vacancies/board')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Night auditor']);

        $this->getJson('/api/careers/vacancies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_user_without_permission_cannot_manage_vacancies(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/vacancies/board')->assertForbidden();
        $this->postJson('/api/vacancies', ['title' => 'Cook'])->assertForbidden();
        $this->getJson('/api/vacancy-stages')->assertForbidden();
    }

    public function test_admin_can_configure_vacancy_stages(): void
    {
        $admin = $this->vacancyAdmin();
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/vacancy-stages', [
            'name' => 'Interviewing',
            'color' => '#f59e0b',
            'lists_public' => false,
        ])->assertCreated();

        $id = $created->json('data.id');
        $ids = VacancyStage::query()->orderBy('sort_order')->pluck('id')->all();

        $this->putJson('/api/vacancy-stages/reorder', ['ids' => array_merge([$id], array_diff($ids, [$id]))])
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->putJson("/api/vacancy-stages/{$id}", [
            'name' => 'Interviewing',
            'color' => '#f59e0b',
            'is_default' => true,
        ])->assertOk()->assertJsonPath('data.is_default', true);

        $this->assertSame(1, VacancyStage::query()->where('is_default', true)->count());
    }

    public function test_vacancy_board_filters_by_job_fields(): void
    {
        $admin = $this->vacancyAdmin();
        Sanctum::actingAs($admin);

        $desk = JobTitle::query()->create(['name' => 'Front Desk']);
        $cook = JobTitle::query()->create(['name' => 'Cook']);
        $new = VacancyStage::query()->where('name', 'New')->firstOrFail();

        Vacancy::query()->create([
            'title' => 'Night auditor',
            'job_title_id' => $desk->id,
            'positions' => 1,
            'require_resume' => false,
            'advertise_internal' => true,
            'advertise_public' => false,
            'vacancy_stage_id' => $new->id,
            'sort_order' => 1,
        ]);
        Vacancy::query()->create([
            'title' => 'Line cook',
            'job_title_id' => $cook->id,
            'positions' => 1,
            'require_resume' => false,
            'advertise_internal' => false,
            'advertise_public' => true,
            'vacancy_stage_id' => $new->id,
            'sort_order' => 2,
        ]);

        $this->getJson('/api/vacancies/board?job_title_id='.$desk->id)
            ->assertOk()
            ->assertJsonFragment(['title' => 'Night auditor'])
            ->assertJsonMissing(['title' => 'Line cook']);

        $this->getJson('/api/vacancies/board?advertise_public=1')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Line cook'])
            ->assertJsonMissing(['title' => 'Night auditor']);
    }

    private function vacancyAdmin(): User
    {
        $view = Permission::query()->firstOrCreate([
            'name' => 'view-vacancies',
            'guard_name' => 'web',
        ]);
        $crud = Permission::query()->firstOrCreate([
            'name' => 'vacancies-crud',
            'guard_name' => 'web',
        ]);

        $admin = User::query()->create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $admin->givePermissionTo([$view, $crud]);

        return $admin;
    }
}
