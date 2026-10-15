<?php

use App\Models\Menu;
use App\Models\Permission;
use App\Modules\Hr\Models\FormTemplate;
use App\Modules\Hr\Models\HrTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('purpose', 32)->default('score_sheet');
            $table->json('schema')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['purpose', 'is_active']);
        });

        Schema::table('vacancies', function (Blueprint $table) {
            $table->uuid('form_template_id')->nullable()->after('vacancy_stage_id');
            $table->foreign('form_template_id')
                ->references('id')
                ->on('form_templates')
                ->nullOnDelete();
        });

        Schema::create('vacancy_panel_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vacancy_id');
            $table->string('name');
            $table->string('email');
            $table->uuid('user_id')->nullable();
            $table->string('invite_code_hash')->nullable();
            $table->timestamp('invite_code_expires_at')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('status', 32)->default('invited');
            $table->timestamps();

            $table->unique(['vacancy_id', 'email']);
            $table->index('email');
            $table->foreign('vacancy_id')->references('id')->on('vacancies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('form_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('form_template_id');
            $table->uuid('vacancy_id');
            $table->uuid('vacancy_application_id');
            $table->uuid('panel_member_id')->nullable();
            $table->uuid('user_id');
            $table->json('schema_snapshot')->nullable();
            $table->json('answers')->nullable();
            $table->text('comments')->nullable();
            $table->decimal('total_score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['vacancy_application_id', 'user_id']);
            $table->foreign('form_template_id')->references('id')->on('form_templates')->restrictOnDelete();
            $table->foreign('vacancy_id')->references('id')->on('vacancies')->cascadeOnDelete();
            $table->foreign('vacancy_application_id')->references('id')->on('vacancy_applications')->cascadeOnDelete();
            $table->foreign('panel_member_id')->references('id')->on('vacancy_panel_members')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $this->seedDefaultScoreSheet();
        $this->seedInviteEmailTemplate();
        $this->seedPermissionsAndMenus();
    }

    public function down(): void
    {
        Schema::dropIfExists('form_responses');
        Schema::dropIfExists('vacancy_panel_members');

        Schema::table('vacancies', function (Blueprint $table) {
            $table->dropForeign(['form_template_id']);
            $table->dropColumn('form_template_id');
        });

        Schema::dropIfExists('form_templates');

        if (Schema::hasTable('hr_templates')) {
            HrTemplate::query()->where('system_key', 'email.panel_invite')->delete();
        }

        if (class_exists(Menu::class) && Schema::hasTable('menus')) {
            Menu::query()->whereIn('system_key', [
                'hr.panel',
                'hr.settings.form_templates',
            ])->delete();
        }
    }

    private function seedDefaultScoreSheet(): void
    {
        if (FormTemplate::query()->where('name', 'Interview score sheet')->exists()) {
            return;
        }

        FormTemplate::query()->create([
            'name' => 'Interview score sheet',
            'description' => 'Standard interview criteria for vacancy panels.',
            'purpose' => FormTemplate::PURPOSE_SCORE_SHEET,
            'is_active' => true,
            'schema' => [
                'fields' => [
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'section',
                        'label' => 'Interview scores',
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'score',
                        'label' => 'Communication',
                        'help' => 'Clarity, listening, and professionalism.',
                        'required' => true,
                        'min' => 0,
                        'max' => 10,
                        'weight' => 1,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'score',
                        'label' => 'Technical skills',
                        'help' => 'Ability to perform the role.',
                        'required' => true,
                        'min' => 0,
                        'max' => 10,
                        'weight' => 1,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'score',
                        'label' => 'Experience',
                        'required' => true,
                        'min' => 0,
                        'max' => 10,
                        'weight' => 1,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'select',
                        'label' => 'Recommendation',
                        'required' => true,
                        'options' => [
                            ['value' => 'strong_hire', 'label' => 'Strong hire'],
                            ['value' => 'hire', 'label' => 'Hire'],
                            ['value' => 'hold', 'label' => 'Hold'],
                            ['value' => 'no', 'label' => 'Do not hire'],
                        ],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'textarea',
                        'label' => 'Comments',
                        'required' => false,
                        'placeholder' => 'Notes for the hiring team',
                    ],
                ],
            ],
        ]);
    }

    private function seedInviteEmailTemplate(): void
    {
        if (! Schema::hasTable('hr_templates')) {
            return;
        }

        $existing = HrTemplate::query()->where('system_key', 'email.panel_invite')->first();
        if ($existing) {
            return;
        }

        HrTemplate::query()->create([
            'channel' => HrTemplate::CHANNEL_EMAIL,
            'category' => 'recruiting',
            'system_key' => 'email.panel_invite',
            'name' => 'Interview panel invitation',
            'subject' => 'You are invited to score {{vacancy_title}}',
            'body' => '<p>Hello {{recipient_name}},</p><p>You have been invited to the interview panel for <strong>{{vacancy_title}}</strong> at {{company_name}}.</p><p>Sign in with your email and this code:</p><p style="font-size:22px;letter-spacing:3px;"><strong>{{invite_code}}</strong></p><p><a href="{{login_url}}">Open the login page</a> and choose “Have an invite code?”.</p><p>{{panel_instructions}}</p>',
            'is_system' => true,
            'is_active' => true,
        ]);
    }

    private function seedPermissionsAndMenus(): void
    {
        if (class_exists(Permission::class) && Schema::hasTable('permissions')) {
            foreach (['score-vacancies', 'form-templates-crud'] as $name) {
                Permission::query()->firstOrCreate([
                    'name' => $name,
                    'guard_name' => 'web',
                ]);
            }
        }

        if (! class_exists(Menu::class) || ! Schema::hasTable('menus')) {
            return;
        }

        $this->ensureMenu([
            'parent_id' => null,
            'title' => 'Interview panel',
            'route' => '/hr/panel',
            'icon' => 'how_to_vote',
            'permission' => 'score-vacancies|view-vacancies',
            'order' => 3,
            'type' => 'menu',
            'system_key' => 'hr.panel',
            'module_code' => 'hr',
        ]);

        $hrSettingsId = Menu::query()->where('system_key', 'hr.settings')->value('id');
        if (! $hrSettingsId) {
            return;
        }

        $this->ensureMenu([
            'parent_id' => $hrSettingsId,
            'title' => 'Form templates',
            'route' => '/hr/settings/form-templates',
            'icon' => 'dynamic_form',
            'permission' => 'view-vacancies',
            'order' => 16,
            'type' => 'submenu',
            'system_key' => 'hr.settings.form_templates',
            'module_code' => 'hr',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ensureMenu(array $attributes): void
    {
        $existing = Menu::query()->where('system_key', $attributes['system_key'])->first();
        if ($existing) {
            return;
        }

        Menu::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'source' => 'system',
            'is_active' => true,
        ], $attributes));
    }
};
