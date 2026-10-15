<?php

namespace App\Modules\Payroll\Services;

use App\Models\DepartmentHeadAssignment;
use App\Models\Employee;
use App\Models\EmployeeReporting;
use App\Models\PayrollRun;
use App\Models\User;
use App\Modules\Payroll\Services\PayPeriodHelper;
use App\Support\Access;
use Illuminate\Support\Collection;

class PayrollAllowanceAuthorizationService
{
    public function actorEmployee(?User $user): ?Employee
    {
        if (! $user) {
            return null;
        }

        return Employee::query()->where('user_id', $user->id)->first();
    }

    /**
     * null = unrestricted (company-wide access). Empty collection = no visible employees.
     *
     * @return Collection<int, string>|null
     */
    public function visibleEmployeeIds(?User $user): ?Collection
    {
        if (! $user) {
            return collect();
        }

        if (Access::canManageCompanyPayrollAllowances($user)) {
            return null;
        }

        $employeeIds = $this->subordinateEmployeeIds($user);
        $departmentIds = $this->headedDepartmentIds($user);

        if ($departmentIds->isNotEmpty()) {
            $departmentEmployeeIds = Employee::query()
                ->whereHas('employmentDetails', function ($query) use ($departmentIds) {
                    $query->where('isActive', true)
                        ->whereIn('departmentId', $departmentIds->all());
                })
                ->pluck('id')
                ->map(fn ($id) => (string) $id);

            $employeeIds = $employeeIds
                ->merge($departmentEmployeeIds)
                ->unique()
                ->values();
        }

        return $employeeIds;
    }

    /**
     * @return Collection<int, string>
     */
    public function subordinateEmployeeIds(?User $user): Collection
    {
        $actor = $this->actorEmployee($user);
        if (! $actor) {
            return collect();
        }

        $fromReporting = EmployeeReporting::query()
            ->where('supervisor_id', $actor->id)
            ->where('is_active', true)
            ->pluck('subordinate_id');

        $fromSupervisorId = Employee::query()
            ->where('supervisorId', $actor->id)
            ->pluck('id');

        return $fromReporting
            ->merge($fromSupervisorId)
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    public function headedDepartmentIds(?User $user): Collection
    {
        $actor = $this->actorEmployee($user);
        if (! $actor) {
            return collect();
        }

        return DepartmentHeadAssignment::query()
            ->where('employeeId', $actor->id)
            ->where('isCurrent', true)
            ->whereNull('endDate')
            ->pluck('departmentId')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function canAccessEmployee(?User $user, string $employeeId): bool
    {
        $visible = $this->visibleEmployeeIds($user);
        if ($visible === null) {
            return true;
        }

        return $visible->contains((string) $employeeId);
    }

    public function assertDraftPayrollRun(PayrollRun $payrollRun): void
    {
        if (strtolower((string) $payrollRun->status) !== 'draft') {
            abort(422, 'Other payments can only be managed for draft payroll runs.');
        }
    }

    public function assertUpcomingEditablePayrollRun(PayrollRun $payrollRun): void
    {
        $this->assertDraftPayrollRun($payrollRun);

        $upcomingIds = PayPeriodHelper::upcomingEditablePayrollRuns()
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        if (! $upcomingIds->contains((string) $payrollRun->id)) {
            abort(422, 'Other payments can only be managed for the upcoming payroll run.');
        }
    }
}
