<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_compensation') && ! Schema::hasColumn('employee_compensation', 'dailyRate')) {
            Schema::table('employee_compensation', function (Blueprint $table) {
                $table->decimal('dailyRate', 12, 2)->default(0)->after('yearlyRate');
            });
        }

        if (! Schema::hasTable('employee_day_work')) {
            Schema::create('employee_day_work', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('employeeId')->constrained('employee')->cascadeOnDelete();
                $table->foreignUuid('employmentDetailId')->nullable()->constrained('employment_detail')->nullOnDelete();
                $table->foreignUuid('employeeCompensationId')->nullable()->constrained('employee_compensation')->nullOnDelete();
                $table->unsignedBigInteger('departmentId')->nullable();
                $table->date('date');
                $table->decimal('units', 8, 2);
                $table->decimal('dailyRate', 12, 2);
                $table->decimal('amount', 12, 2);
                $table->text('note')->nullable();
                $table->string('approvalStatus', 32)->default('APPROVED');
                $table->date('approvalDate')->nullable();
                $table->uuid('approverId')->nullable();
                $table->uuid('createdById')->nullable();
                $table->uuid('updatedById')->nullable();
                $table->timestamps();

                $table->unique(['employeeId', 'date'], 'employee_day_work_employee_date_unique');
                $table->index(['date', 'approvalStatus'], 'employee_day_work_date_status_idx');
                $table->index(['departmentId', 'date'], 'employee_day_work_department_date_idx');
            });

            if (Schema::hasTable('department')) {
                Schema::table('employee_day_work', function (Blueprint $table) {
                    $table->foreign('departmentId')->references('id')->on('department')->nullOnDelete();
                });
            }

            if (Schema::hasTable('employee')) {
                Schema::table('employee_day_work', function (Blueprint $table) {
                    $table->foreign('approverId')->references('id')->on('employee')->nullOnDelete();
                    $table->foreign('createdById')->references('id')->on('employee')->nullOnDelete();
                    $table->foreign('updatedById')->references('id')->on('employee')->nullOnDelete();
                });
            }
        }

        if (Schema::hasTable('permissions')) {
            foreach (['view-employee-day-work', 'employee-day-work-crud'] as $permissionName) {
                Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);
            }

            Role::query()
                ->whereHas('permissions', function ($query) {
                    $query->whereIn('name', [
                        'pay-employees-crud',
                        'employees-crud',
                        'view-payroll',
                        'timesheets-crud',
                        'view-timesheets',
                        'payroll-allowances-crud',
                        'view-payroll-allowances',
                    ]);
                })
                ->get()
                ->each(fn (Role $role) => $role->givePermissionTo([
                    'view-employee-day-work',
                    'employee-day-work-crud',
                ]));
        }

        if (Schema::hasTable('menus')) {
            $payrollMenuId = DB::table('menus')
                ->where('route', '/payroll')
                ->where('type', 'menu')
                ->value('id');

            if ($payrollMenuId) {
                $existing = DB::table('menus')->where('route', '/payroll/day-work')->first();
                $payload = [
                    'parent_id' => $payrollMenuId,
                    'title' => 'Day / trip work',
                    'icon' => 'fas fa-route',
                    'permission' => 'view-employee-day-work',
                    'order' => 5,
                    'type' => 'submenu',
                    'is_active' => true,
                    'updated_at' => now(),
                ];

                if ($existing) {
                    DB::table('menus')->where('id', $existing->id)->update($payload);
                } else {
                    DB::table('menus')->insert([
                        'id' => (string) Str::uuid(),
                        ...$payload,
                        'route' => '/payroll/day-work',
                        'created_at' => now(),
                    ]);
                }

                DB::table('menus')
                    ->where('parent_id', $payrollMenuId)
                    ->where('route', '/payroll/generate-payslip')
                    ->update(['order' => 6]);

                DB::table('menus')
                    ->where('parent_id', $payrollMenuId)
                    ->where('route', '/payroll/taxes-filing')
                    ->update(['order' => 7]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')->where('route', '/payroll/day-work')->delete();

            $payrollMenuId = DB::table('menus')
                ->where('route', '/payroll')
                ->where('type', 'menu')
                ->value('id');

            if ($payrollMenuId) {
                DB::table('menus')
                    ->where('parent_id', $payrollMenuId)
                    ->where('route', '/payroll/generate-payslip')
                    ->update(['order' => 5]);

                DB::table('menus')
                    ->where('parent_id', $payrollMenuId)
                    ->where('route', '/payroll/taxes-filing')
                    ->update(['order' => 6]);
            }
        }

        Schema::dropIfExists('employee_day_work');

        if (Schema::hasTable('employee_compensation') && Schema::hasColumn('employee_compensation', 'dailyRate')) {
            Schema::table('employee_compensation', function (Blueprint $table) {
                $table->dropColumn('dailyRate');
            });
        }
    }
};
