<?php

namespace App\Modules\Hr\Services\ProfileChange;

use App\Enums\PipelineAssigneeType;
use App\Models\DepartmentHeadAssignment;
use App\Models\EmployeeReporting;
use App\Models\EmploymentDetail;
use App\Models\PipelineInstance;
use App\Models\PipelineTemplateStep;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeProfileChangeRequest;
use App\Support\Access;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProfileChangeAssigneeResolver
{
    /**
     * @return Collection<int, User>
     */
    public function usersForCurrentStep(EmployeeProfileChangeRequest $request, ?PipelineInstance $instance): Collection
    {
        $step = $instance?->currentStep;
        if (! $step) {
            return collect();
        }

        $request->loadMissing('employee');

        return $this->usersForStep($request->employee, $step);
    }

    /**
     * @return Collection<int, User>
     */
    public function usersForStep(?Employee $employee, PipelineTemplateStep $step): Collection
    {
        $employee?->loadMissing(['user', 'supervisor.user', 'person']);
        $type = PipelineAssigneeType::tryFrom((string) $step->assignee_type);

        $users = match ($type) {
            PipelineAssigneeType::Supervisor => $this->supervisorUsers($employee),
            PipelineAssigneeType::SkipLevel => $this->skipLevelUsers($employee),
            PipelineAssigneeType::DepartmentHead => $this->departmentHeadUsers($employee),
            PipelineAssigneeType::SubjectOwner => collect([$employee?->user])->filter(),
            PipelineAssigneeType::Admin => $this->hrUsers(),
            PipelineAssigneeType::Role => $this->usersForRole((string) $step->assignee_role),
            default => collect(),
        };

        return $users
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->values();
    }

    public function canAct(?User $actor, EmployeeProfileChangeRequest $request, ?PipelineInstance $instance): bool
    {
        if (! $actor || ! $request->isOpen()) {
            return false;
        }

        $request->loadMissing('employee');
        if ($request->employee?->user_id && (string) $request->employee->user_id === (string) $actor->id) {
            return $this->isSubjectOwnerStep($instance);
        }

        $step = $instance?->currentStep;
        if (! $step) {
            return Access::canConfirmHrProfileChange($actor);
        }

        if ($this->isHrStep($step) && Access::canConfirmHrProfileChange($actor)) {
            return true;
        }

        return $this->usersForStep($request->employee, $step)
            ->contains(fn (User $user) => (string) $user->id === (string) $actor->id);
    }

    public function canCancel(?User $actor, EmployeeProfileChangeRequest $request): bool
    {
        if (! $actor || ! $request->isOpen()) {
            return false;
        }

        $request->loadMissing('employee');
        if ($request->employee?->user_id && (string) $request->employee->user_id === (string) $actor->id) {
            return true;
        }

        if ((string) $request->submitted_by_user_id === (string) $actor->id) {
            return true;
        }

        return Access::canConfirmHrProfileChange($actor);
    }

    public function isHrStep(?PipelineTemplateStep $step): bool
    {
        if (! $step) {
            return false;
        }

        $mode = strtolower((string) data_get($step->ui_config, 'mode'));
        $key = strtolower((string) $step->key);
        $role = strtolower(trim((string) $step->assignee_role));

        return $mode === 'hr_decision'
            || $key === 'hr'
            || $step->assignee_type === PipelineAssigneeType::Admin->value
            || $role === 'hr';
    }

    public function canSkipIfUnresolved(?PipelineTemplateStep $step): bool
    {
        if (! $step || $this->isHrStep($step)) {
            return false;
        }

        $type = PipelineAssigneeType::tryFrom((string) $step->assignee_type);

        return in_array($type, [
            PipelineAssigneeType::Supervisor,
            PipelineAssigneeType::SkipLevel,
            PipelineAssigneeType::DepartmentHead,
        ], true);
    }

    private function isSubjectOwnerStep(?PipelineInstance $instance): bool
    {
        return $instance?->currentStep?->assignee_type === PipelineAssigneeType::SubjectOwner->value;
    }

    /**
     * @return Collection<int, User>
     */
    private function supervisorUsers(?Employee $employee): Collection
    {
        if (! $employee) {
            return collect();
        }

        $ids = EmployeeReporting::query()
            ->where('subordinate_id', $employee->id)
            ->where('is_active', true)
            ->pluck('supervisor_id');

        if ($employee->supervisorId) {
            $ids->push($employee->supervisorId);
        }

        return $this->usersForEmployeeIds($ids->unique()->filter()->all());
    }

    /**
     * @return Collection<int, User>
     */
    private function skipLevelUsers(?Employee $employee): Collection
    {
        $supervisors = $this->supervisorEmployees($employee);
        $users = collect();
        foreach ($supervisors as $supervisor) {
            $users = $users->merge($this->supervisorUsers($supervisor));
        }

        return $users->unique('id')->values();
    }

    /**
     * @return Collection<int, Employee>
     */
    private function supervisorEmployees(?Employee $employee): Collection
    {
        if (! $employee) {
            return collect();
        }

        $ids = EmployeeReporting::query()
            ->where('subordinate_id', $employee->id)
            ->where('is_active', true)
            ->pluck('supervisor_id');

        if ($employee->supervisorId) {
            $ids->push($employee->supervisorId);
        }

        return Employee::query()
            ->with('user')
            ->whereIn('id', $ids->unique()->filter()->all())
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function departmentHeadUsers(?Employee $employee): Collection
    {
        $departmentId = $this->activeDepartmentId($employee);
        if (! $departmentId) {
            return collect();
        }

        $headIds = DepartmentHeadAssignment::query()
            ->where('departmentId', $departmentId)
            ->where('isCurrent', true)
            ->whereNull('endDate')
            ->pluck('employeeId');

        return $this->usersForEmployeeIds($headIds->all());
    }

    /**
     * @param  list<mixed>  $employeeIds
     * @return Collection<int, User>
     */
    private function usersForEmployeeIds(array $employeeIds): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        return Employee::query()
            ->with('user')
            ->whereIn('id', $employeeIds)
            ->get()
            ->pluck('user')
            ->filter();
    }

    /**
     * @return Collection<int, User>
     */
    private function hrUsers(): Collection
    {
        return $this->usersWithPermissions(['employees-crud', 'view-hr-settings'])
            ->merge($this->usersWithRoles(['admin', 'hr']))
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function usersForRole(string $role): Collection
    {
        $normalized = Str::lower(trim($role));
        if ($normalized === '' || in_array($normalized, ['admin', 'hr'], true)) {
            return $this->hrUsers();
        }

        return $this->usersWithRoles([$normalized]);
    }

    /**
     * @param  list<string>  $names
     * @return Collection<int, User>
     */
    private function usersWithPermissions(array $names): Collection
    {
        return User::query()
            ->where(function ($query) use ($names) {
                $query->whereHas('permissions', fn ($permission) => $permission->whereIn('name', $names))
                    ->orWhereHas('roles.permissions', fn ($permission) => $permission->whereIn('name', $names));
            })
            ->get();
    }

    /**
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    private function usersWithRoles(array $roles): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))
            ->get();
    }

    private function activeDepartmentId(?Employee $employee): ?int
    {
        if (! $employee) {
            return null;
        }

        $departmentId = EmploymentDetail::query()
            ->where('employeeId', $employee->id)
            ->where('isActive', true)
            ->value('departmentId');

        return $departmentId ? (int) $departmentId : null;
    }
}
