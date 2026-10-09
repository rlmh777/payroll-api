<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Modules\Hr\Models\HrTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HrTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_letter_and_email_system_templates_are_listed(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->getJson('/api/hr-templates?channel=letter')
            ->assertOk()
            ->assertJsonFragment(['system_key' => 'letter.bank'])
            ->assertJsonFragment(['system_key' => 'letter.embassy']);

        $this->getJson('/api/hr-templates?channel=email')
            ->assertOk()
            ->assertJsonFragment(['system_key' => 'email.leave_approved'])
            ->assertJsonFragment(['system_key' => 'email.password_reset'])
            ->assertJsonFragment(['system_key' => 'email.job_application_received'])
            ->assertJsonFragment(['system_key' => 'email.contract_expiring']);
    }

    public function test_system_templates_can_be_updated_but_not_deleted(): void
    {
        Sanctum::actingAs($this->hrUser());
        $template = HrTemplate::query()->where('system_key', 'letter.bank')->firstOrFail();

        $this->putJson("/api/hr-templates/{$template->id}", [
            'channel' => 'letter',
            'category' => 'bank',
            'name' => 'Bank confirmation',
            'body' => '<p>{{employee_name}} is employed.</p>',
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Bank confirmation');

        $this->deleteJson("/api/hr-templates/{$template->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('hr_templates', ['id' => $template->id]);
    }

    public function test_custom_email_template_can_be_created_and_deleted(): void
    {
        Sanctum::actingAs($this->hrUser());

        $created = $this->postJson('/api/hr-templates', [
            'channel' => 'email',
            'category' => 'other',
            'name' => 'Welcome email',
            'subject' => 'Welcome to {{company_name}}',
            'body' => '<p>Hello {{employee_name}}</p>',
            'is_active' => true,
        ])->assertCreated()->json('data');

        $this->deleteJson("/api/hr-templates/{$created['id']}")
            ->assertOk();

        $this->assertDatabaseMissing('hr_templates', ['id' => $created['id']]);
    }

    private function hrUser(): User
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'view-hr-settings',
            'guard_name' => 'web',
        ]);

        $user = User::query()->create([
            'name' => 'HR',
            'username' => 'hr.templates',
            'email' => 'hr-templates@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->givePermissionTo($permission);

        return $user;
    }
}
