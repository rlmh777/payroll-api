<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeReporting;
use App\Models\EmploymentDetail;
use App\Models\User;
use App\Modules\Hr\Services\Department\DepartmentHeadAssignmentService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SupervisorDepartmentHeadSeeder extends Seeder
{
    public function run(): void
    {
        $supervisorUser = User::query()->where('email', 'supervisor@example.com')->first();
        if (!$supervisorUser) {
            $this->command?->warn('SupervisorDepartmentHeadSeeder skipped: supervisor@example.com not found.');

            return;
        }

        $supervisorEmployee = Employee::query()->where('user_id', $supervisorUser->id)->first();
        if (!$supervisorEmployee) {
            $this->command?->warn('SupervisorDepartmentHeadSeeder skipped: no employee linked to supervisor user.');

            return;
        }

        $departmentId = EmploymentDetail::query()
            ->where('employeeId', $supervisorEmployee->id)
            ->where('isActive', true)
            ->value('departmentId');

        if (!$departmentId) {
            $departmentId = Department::query()->where('name', 'Operations')->value('id')
                ?? Department::query()->orderBy('name')->value('id');
        }

        if (!$departmentId) {
            $this->command?->warn('SupervisorDepartmentHeadSeeder skipped: no department found.');

            return;
        }

        $assignmentService = app(DepartmentHeadAssignmentService::class);
        $assignmentService->appoint([
            'departmentId' => (int) $departmentId,
            'employeeId' => (string) $supervisorEmployee->id,
            'startDate' => Carbon::today()->subYear()->toDateString(),
            'notes' => 'Seeded department head for timesheet review demo.',
        ]);

        $subordinateIds = EmployeeReporting::query()
            ->where('supervisor_id', $supervisorEmployee->id)
            ->where('is_active', true)
            ->pluck('subordinate_id');

        if ($subordinateIds->isNotEmpty()) {
            EmploymentDetail::query()
                ->whereIn('employeeId', $subordinateIds)
                ->where('isActive', true)
                ->update(['departmentId' => $departmentId]);
        }

        $departmentName = Department::query()->whereKey($departmentId)->value('name');
        $this->command?->info(sprintf(
            'Appointed supervisor@example.com as head of %s (%d subordinates aligned to department).',
            $departmentName ?? "department #{$departmentId}",
            $subordinateIds->count(),
        ));
    }
}
