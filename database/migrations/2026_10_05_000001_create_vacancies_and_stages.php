<?php

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 32)->default('#64748b');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('lists_public')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
        });

        Schema::create('vacancies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->unsignedBigInteger('job_title_id')->nullable();
            $table->unsignedBigInteger('worksite_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->uuid('hiring_manager_id')->nullable();
            $table->unsignedInteger('positions')->default(1);
            $table->boolean('require_resume')->default(true);
            $table->text('description')->nullable();
            $table->boolean('advertise_internal')->default(true);
            $table->boolean('advertise_public')->default(false);
            $table->unsignedBigInteger('vacancy_stage_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('job_title_id')->references('id')->on('job_title')->nullOnDelete();
            $table->foreign('worksite_id')->references('id')->on('worksite')->nullOnDelete();
            $table->foreign('department_id')->references('id')->on('department')->nullOnDelete();
            $table->foreign('hiring_manager_id')->references('id')->on('employee')->nullOnDelete();
            $table->foreign('vacancy_stage_id')->references('id')->on('vacancy_stages')->restrictOnDelete();
            $table->index(['vacancy_stage_id', 'sort_order']);
        });

        $now = now();
        DB::table('vacancy_stages')->insert([
            [
                'name' => 'New',
                'color' => '#3b82f6',
                'sort_order' => 1,
                'is_default' => true,
                'lists_public' => false,
                'is_closed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Draft',
                'color' => '#94a3b8',
                'sort_order' => 2,
                'is_default' => false,
                'lists_public' => false,
                'is_closed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Published',
                'color' => '#22c55e',
                'sort_order' => 3,
                'is_default' => false,
                'lists_public' => true,
                'is_closed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Closed',
                'color' => '#64748b',
                'sort_order' => 4,
                'is_default' => false,
                'lists_public' => false,
                'is_closed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $view = Permission::firstOrCreate([
            'name' => 'view-vacancies',
            'guard_name' => 'web',
        ]);
        $crud = Permission::firstOrCreate([
            'name' => 'vacancies-crud',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->whereIn('name', ['admin'])
            ->get()
            ->each(function (Role $role) use ($view, $crud) {
                $role->givePermissionTo([$view, $crud]);
                $role->users()->get()->each(
                    fn ($user) => $user->givePermissionTo([$view, $crud]),
                );
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->ensureMenus();
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->whereIn('system_key', [
                'hr.employees.vacancies',
                'payroll.employees.vacancies',
                'payroll.settings.vacancy_stages',
            ])->delete();
        }

        Permission::query()->whereIn('name', ['view-vacancies', 'vacancies-crud'])->delete();
        Schema::dropIfExists('vacancies');
        Schema::dropIfExists('vacancy_stages');
    }

    private function ensureMenus(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        $hrEmployeesId = Menu::query()->where('system_key', 'hr.employees')->value('id');
        if ($hrEmployeesId) {
            Menu::query()->updateOrCreate(
                ['system_key' => 'hr.employees.vacancies'],
                [
                    'parent_id' => $hrEmployeesId,
                    'title' => 'Vacancies',
                    'route' => '/hr/employees/vacancies',
                    'icon' => 'work',
                    'permission' => 'view-vacancies',
                    'order' => 3,
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
                ['system_key' => 'payroll.employees.vacancies'],
                [
                    'parent_id' => $payrollEmployeesId,
                    'title' => 'Vacancies',
                    'route' => '/payroll/employees/vacancies',
                    'icon' => 'work',
                    'permission' => 'view-vacancies',
                    'order' => 3,
                    'is_active' => true,
                    'type' => 'submenu',
                    'module_code' => 'payroll',
                    'source' => 'system',
                ],
            );
        }

        $generalId = Menu::query()->where('system_key', 'payroll.settings.general')->value('id');
        if ($generalId) {
            Menu::query()->updateOrCreate(
                ['system_key' => 'payroll.settings.vacancy_stages'],
                [
                    'parent_id' => $generalId,
                    'title' => 'Vacancy Stages',
                    'route' => '/payroll/settings/vacancy-stages',
                    'icon' => 'view_kanban',
                    'permission' => 'view-vacancies',
                    'order' => 18,
                    'is_active' => true,
                    'type' => 'submenu',
                    'module_code' => 'payroll',
                    'source' => 'system',
                ],
            );
        }
    }
};
