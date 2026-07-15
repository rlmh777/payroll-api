<?php

namespace App\Modules\Hr\Services;

use App\Models\EmployeeReporting;
use App\Modules\Hr\Services\Employee\EmployeeUserProvisioner;
use App\Modules\Core\Models\Person;
use App\Modules\Hr\Models\Employee;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EmployeePersonSync
{
    public static function personAttributeKeys(): array
    {
        return Person::ATTRIBUTE_KEYS;
    }

    /**
     * @return array{person: array<string, mixed>, employee: array<string, mixed>}
     */
    public static function splitAttributes(array $attributes): array
    {
        $person = Arr::only($attributes, self::personAttributeKeys());
        $employee = Arr::except($attributes, self::personAttributeKeys());

        return compact('person', 'employee');
    }

    public static function create(array $attributes): Employee
    {
        return DB::transaction(function () use ($attributes) {
            ['person' => $personData, 'employee' => $employeeData] = self::splitAttributes($attributes);

            $person = Person::create($personData);
            $employeeData['person_id'] = $person->id;

            $employee = Employee::query()->create($employeeData);
            self::syncSupervisorReporting($employee);

            if (empty($employeeData['user_id'])) {
                app(EmployeeUserProvisioner::class)->provisionForEmployee($employee);
            }

            return $employee->fresh();
        });
    }

    public static function update(Employee $employee, array $attributes): Employee
    {
        return DB::transaction(function () use ($employee, $attributes) {
            ['person' => $personData, 'employee' => $employeeData] = self::splitAttributes($attributes);

            if ($personData !== []) {
                $employee->person()->update($personData);
            }

            $supervisorChanged = array_key_exists('supervisorId', $employeeData);

            if ($employeeData !== []) {
                $employee->update($employeeData);
            }

            if ($supervisorChanged) {
                self::syncSupervisorReporting($employee->fresh());
            }

            return $employee->fresh();
        });
    }

    public static function defaultRelations(): array
    {
        return [
            'user',
            'person.locality',
            'person.honorific',
            'person.gender',
            'person.citizenshipStatus',
            'person.nationality',
            'employmentStatus',
            'employeeStatus',
            'timesheetTemplate',
            'payrateFrequency',
            'paymentMethod',
            'supervisor.person',
        ];
    }

    public static function detailRelations(): array
    {
        return array_merge(self::defaultRelations(), [
            'employmentDetails.department',
            'employmentDetails.worksite',
            'employmentDetails.contractType',
            'employmentDetails.jobTitle',
            'employmentDetails.defaultPayPeriodGroup',
            'employeeCompensations',
            'allowances',
            'employeeBanks',
            'contacts',
            'employeeDefaultDeductions',
            'qualifications',
        ]);
    }

    /**
     * Keep employee_reporting in sync with employee.supervisorId so leave/team
     * lists that use reporting continue to work.
     */
    private static function syncSupervisorReporting(?Employee $employee): void
    {
        if (!$employee) {
            return;
        }

        EmployeeReporting::query()
            ->where('subordinate_id', $employee->id)
            ->where('reporting_method', 'direct')
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $supervisorId = $employee->supervisorId;
        if (!$supervisorId || (string) $supervisorId === (string) $employee->id) {
            return;
        }

        EmployeeReporting::query()->updateOrCreate(
            [
                'supervisor_id' => $supervisorId,
                'subordinate_id' => $employee->id,
                'reporting_method' => 'direct',
            ],
            [
                'is_active' => true,
                'effective_date' => now()->toDateString(),
            ],
        );
    }
}
