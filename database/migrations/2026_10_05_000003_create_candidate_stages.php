<?php

use App\Models\Menu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 32)->default('#64748b');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_hired')->default(false);
            $table->boolean('is_rejected')->default(false);
            $table->timestamps();
        });

        $now = now();
        DB::table('candidate_stages')->insert([
            [
                'name' => 'Application received',
                'color' => '#3b82f6',
                'sort_order' => 1,
                'is_default' => true,
                'is_hired' => false,
                'is_rejected' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Short listed',
                'color' => '#8b5cf6',
                'sort_order' => 2,
                'is_default' => false,
                'is_hired' => false,
                'is_rejected' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'In progress',
                'color' => '#f59e0b',
                'sort_order' => 3,
                'is_default' => false,
                'is_hired' => false,
                'is_rejected' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Job offer',
                'color' => '#06b6d4',
                'sort_order' => 4,
                'is_default' => false,
                'is_hired' => false,
                'is_rejected' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Preboarding',
                'color' => '#14b8a6',
                'sort_order' => 5,
                'is_default' => false,
                'is_hired' => false,
                'is_rejected' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Hired',
                'color' => '#22c55e',
                'sort_order' => 6,
                'is_default' => false,
                'is_hired' => true,
                'is_rejected' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Rejected',
                'color' => '#ef4444',
                'sort_order' => 7,
                'is_default' => false,
                'is_hired' => false,
                'is_rejected' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::table('vacancy_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('candidate_stage_id')->nullable()->after('applicant_id');
            $table->unsignedInteger('sort_order')->default(0)->after('status');
            $table->foreign('candidate_stage_id')
                ->references('id')
                ->on('candidate_stages')
                ->restrictOnDelete();
            $table->index(['candidate_stage_id', 'sort_order']);
        });

        $defaultStageId = DB::table('candidate_stages')->where('is_default', true)->value('id');
        if ($defaultStageId) {
            DB::table('vacancy_applications')
                ->whereNull('candidate_stage_id')
                ->update(['candidate_stage_id' => $defaultStageId]);
        }

        $this->ensureMenus();
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->whereIn('system_key', [
                'hr.employees.candidates',
                'payroll.employees.candidates',
                'payroll.settings.candidate_stages',
            ])->delete();
        }

        Schema::table('vacancy_applications', function (Blueprint $table) {
            $table->dropForeign(['candidate_stage_id']);
            $table->dropIndex(['candidate_stage_id', 'sort_order']);
            $table->dropColumn(['candidate_stage_id', 'sort_order']);
        });
        Schema::dropIfExists('candidate_stages');
    }

    private function ensureMenus(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        $hrEmployeesId = Menu::query()->where('system_key', 'hr.employees')->value('id');
        if ($hrEmployeesId) {
            Menu::query()->updateOrCreate(
                ['system_key' => 'hr.employees.candidates'],
                [
                    'parent_id' => $hrEmployeesId,
                    'title' => 'Candidates',
                    'route' => '/hr/employees/candidates',
                    'icon' => 'badge',
                    'permission' => 'view-vacancies',
                    'order' => 4,
                    'is_active' => true,
                    'type' => 'submenu',
                    'module_code' => 'hr',
                    'source' => 'system',
                ],
            );
        }

        $payrollEmployeesId = Menu::query()->where('system_key', 'payroll.employees')->value('id');
        if ($payrollEmployeesId) {
            Menu::query()->updateOrCreate(
                ['system_key' => 'payroll.employees.candidates'],
                [
                    'parent_id' => $payrollEmployeesId,
                    'title' => 'Candidates',
                    'route' => '/payroll/employees/candidates',
                    'icon' => 'badge',
                    'permission' => 'view-vacancies',
                    'order' => 4,
                    'is_active' => true,
                    'type' => 'submenu',
                    'module_code' => 'payroll',
                    'source' => 'system',
                ],
            );
        }

        $generalId = Menu::query()->where('system_key', 'payroll.settings.general')->value('id');
        if ($generalId) {
            Menu::query()->where('system_key', 'payroll.settings.vacancy_stages')->update([
                'title' => 'Vacancy pipeline',
            ]);
            Menu::query()->updateOrCreate(
                ['system_key' => 'payroll.settings.candidate_stages'],
                [
                    'parent_id' => $generalId,
                    'title' => 'Candidate pipeline',
                    'route' => '/payroll/settings/candidate-stages',
                    'icon' => 'account_tree',
                    'permission' => 'view-vacancies',
                    'order' => 19,
                    'is_active' => true,
                    'type' => 'submenu',
                    'module_code' => 'payroll',
                    'source' => 'system',
                ],
            );
        }
    }
};
