<?php

namespace App\Services\Department;

use App\Models\DepartmentHeadAssignment;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DepartmentHeadAssignmentService
{
    /**
     * @param array{
     *   departmentId:int,
     *   employeeId:string,
     *   startDate:string,
     *   notes?:?string,
     *   appointedById?:?string
     * } $data
     */
    public function appoint(array $data): DepartmentHeadAssignment
    {
        return DB::transaction(function () use ($data) {
            $startDate = Carbon::parse($data['startDate'])->startOfDay();
            $this->closeCurrentAssignment((int) $data['departmentId'], $startDate->copy()->subDay());

            return DepartmentHeadAssignment::create([
                'id' => (string) Str::uuid(),
                'departmentId' => $data['departmentId'],
                'employeeId' => $data['employeeId'],
                'startDate' => $startDate->toDateString(),
                'endDate' => null,
                'isCurrent' => true,
                'appointedById' => $data['appointedById'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    public function endAssignment(DepartmentHeadAssignment $assignment, Carbon $endDate): DepartmentHeadAssignment
    {
        if (!$assignment->isCurrent || $assignment->endDate !== null) {
            throw ValidationException::withMessages([
                'endDate' => ['Only the current department head assignment can be ended.'],
            ]);
        }

        $startDate = Carbon::parse($assignment->startDate)->startOfDay();
        if ($endDate->lt($startDate)) {
            throw ValidationException::withMessages([
                'endDate' => ['End date cannot be before the assignment start date.'],
            ]);
        }

        $assignment->endDate = $endDate->toDateString();
        $assignment->isCurrent = false;
        $assignment->save();

        return $assignment->fresh(['department', 'employee', 'appointedBy']);
    }

    public function resolveAppointedById(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }

        return Employee::query()->where('user_id', $userId)->value('id');
    }

    private function closeCurrentAssignment(int $departmentId, Carbon $closeDate): void
    {
        $current = DepartmentHeadAssignment::query()
            ->where('departmentId', $departmentId)
            ->whereNull('endDate')
            ->where('isCurrent', true)
            ->lockForUpdate()
            ->first();

        if (!$current) {
            return;
        }

        $startDate = Carbon::parse($current->startDate)->startOfDay();
        $current->endDate = $closeDate->lt($startDate)
            ? $startDate->toDateString()
            : $closeDate->toDateString();
        $current->isCurrent = false;
        $current->save();
    }
}
