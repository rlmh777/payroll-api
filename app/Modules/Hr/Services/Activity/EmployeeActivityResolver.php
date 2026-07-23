<?php

namespace App\Modules\Hr\Services\Activity;

use App\Models\EmployeeIncident;
use App\Models\EmployeeLeave;
use App\Models\EmploymentDetail;
use App\Modules\Core\Models\Person;
use App\Modules\Hr\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmployeeActivityResolver
{
    /**
     * @return list<string>
     */
    public function resolveEmployeeIds(Model $model): array
    {
        if ($model instanceof Employee) {
            return [(string) $model->getKey()];
        }

        if ($model instanceof Person) {
            $ids = Employee::query()
                ->where('person_id', $model->getKey())
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            return array_values(array_unique($ids));
        }

        foreach (['employeeId', 'employee_id'] as $attribute) {
            $value = $model->getAttribute($attribute);
            if (filled($value)) {
                return [(string) $value];
            }
        }

        if ($model instanceof \App\Models\EmployeeReporting) {
            $ids = [];
            foreach (['subordinate_id', 'supervisor_id', 'subordinateId', 'supervisorId'] as $attribute) {
                $value = $model->getAttribute($attribute);
                if (filled($value)) {
                    $ids[] = (string) $value;
                }
            }

            return array_values(array_unique($ids));
        }

        if (method_exists($model, 'getAttribute') && filled($model->getAttribute('employmentDetailId'))) {
            $employeeId = EmploymentDetail::query()
                ->whereKey($model->getAttribute('employmentDetailId'))
                ->value('employeeId');

            return filled($employeeId) ? [(string) $employeeId] : [];
        }

        if ($model instanceof \App\Models\EmployeeIncidentAttachment) {
            $incidentId = $model->getAttribute('employeeIncidentId')
                ?? $model->getAttribute('employee_incident_id')
                ?? $model->getAttribute('incidentId');
            if (filled($incidentId)) {
                $employeeId = EmployeeIncident::query()->whereKey($incidentId)->value('employeeId');

                return filled($employeeId) ? [(string) $employeeId] : [];
            }
        }

        if ($model instanceof \App\Models\EmployeeLeaveAttachment) {
            $leaveId = $model->getAttribute('employeeLeaveId')
                ?? $model->getAttribute('employee_leave_id')
                ?? $model->getAttribute('leaveId');
            if (filled($leaveId)) {
                $employeeId = EmployeeLeave::query()->whereKey($leaveId)->value('employeeId');

                return filled($employeeId) ? [(string) $employeeId] : [];
            }
        }

        return [];
    }

    public function resourceLabel(Model $model): string
    {
        $table = method_exists($model, 'getTable') ? $model->getTable() : class_basename($model);

        return Str::of($table)->replace('_', ' ')->title()->toString();
    }
}
