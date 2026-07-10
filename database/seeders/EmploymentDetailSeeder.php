<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeStatus;
use App\Models\EmploymentDetail;
use App\Models\EmploymentStatus;
use App\Models\PayPeriodGroup;
use App\Models\Worksite;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EmploymentDetailSeeder extends Seeder
{
    public function run(): void
    {
        $departments = Department::query()->orderBy('name')->get();
        $worksites = Worksite::query()->orderBy('name')->get();
        $employeeStatus = EmployeeStatus::query()->where('name', 'active')->first()
            ?? EmployeeStatus::query()->first();
        $employmentStatus = EmploymentStatus::query()->where('name', 'Full-Time')->first()
            ?? EmploymentStatus::query()->first();
        $contractType = ContractType::query()->where('name', 'Permanent')->first()
            ?? ContractType::query()->first();
        $account = Account::query()->where('code1', '6101')->first()
            ?? Account::query()->first();
        $payPeriodGroup = PayPeriodGroup::query()->where('isDefault', true)->first()
            ?? PayPeriodGroup::query()->first();
        $monthlyPayPeriodGroup = PayPeriodGroup::query()->where('name', 'Monthly Payroll')->first()
            ?? $payPeriodGroup;
        $biweeklyPayPeriodGroup = PayPeriodGroup::query()->where('name', 'Biweekly Payroll')->first()
            ?? $payPeriodGroup;
        $monthlyFrequencyId = \App\Models\PayrateFrequency::query()->where('name', 'Monthly')->value('id');
        $biweeklyFrequencyId = \App\Models\PayrateFrequency::query()->where('name', 'Biweekly')->value('id');

        if (
            $departments->isEmpty() ||
            $worksites->isEmpty() ||
            !$employeeStatus ||
            !$employmentStatus ||
            !$contractType ||
            !$account ||
            !$payPeriodGroup
        ) {
            $this->command?->warn('EmploymentDetailSeeder skipped: missing reference data.');
            return;
        }

        $employees = Employee::query()->orderBy('lastName')->orderBy('firstName')->get();

        if ($employees->isEmpty()) {
            $this->command?->warn('EmploymentDetailSeeder skipped: no employees found.');
            return;
        }

        $this->assignEmployeesToDepartments(
            $employees,
            $departments,
            $worksites,
            $employeeStatus,
            $employmentStatus,
            $contractType,
            $account,
            $payPeriodGroup,
            $monthlyPayPeriodGroup,
            $biweeklyPayPeriodGroup,
            $monthlyFrequencyId,
            $biweeklyFrequencyId,
        );

        $this->command?->info('Employment details seeded. Employees per department:');
        foreach ($departments as $department) {
            $count = EmploymentDetail::query()
                ->where('departmentId', $department->id)
                ->where('isActive', true)
                ->count();
            $this->command?->line("  - {$department->name}: {$count}");
        }
    }

    private function assignEmployeesToDepartments(
        Collection $employees,
        Collection $departments,
        Collection $worksites,
        EmployeeStatus $employeeStatus,
        EmploymentStatus $employmentStatus,
        ContractType $contractType,
        Account $account,
        PayPeriodGroup $payPeriodGroup,
        PayPeriodGroup $monthlyPayPeriodGroup,
        PayPeriodGroup $biweeklyPayPeriodGroup,
        ?int $monthlyFrequencyId,
        ?int $biweeklyFrequencyId,
    ): void {
        $departmentCount = $departments->count();

        foreach ($employees as $index => $employee) {
            $department = $departments[$index % $departmentCount];
            $worksite = $worksites[$index % $worksites->count()];
            $hourlyRate = fake()->randomFloat(2, 8, 35);
            $yearlyRate = round($hourlyRate * 40 * 52, 2);
            $payMethods = ['HOURLY_NO_OT', 'HOURLY_OT', 'BASE_NO_OT', 'BASE_OT'];
            $compensationMethod = $payMethods[$index % count($payMethods)];
            $requiresClocking = in_array($compensationMethod, ['HOURLY_NO_OT', 'HOURLY_OT', 'BASE_OT'], true);
            $startDate = now()->subMonths(fake()->numberBetween(1, 36))->toDateString();
            $endDate = now()->addYears(2)->toDateString();
            $employeePayPeriodGroup = $payPeriodGroup;

            if (
                $biweeklyFrequencyId !== null
                && (int) $employee->payrateFrequencyId === (int) $biweeklyFrequencyId
            ) {
                $employeePayPeriodGroup = $biweeklyPayPeriodGroup;
            } elseif (
                $monthlyFrequencyId !== null
                && (int) $employee->payrateFrequencyId === (int) $monthlyFrequencyId
            ) {
                $employeePayPeriodGroup = $monthlyPayPeriodGroup;
            }

            $payload = [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'isActive' => true,
                'jobTitle' => fake()->jobTitle(),
                'requiresClocking' => $requiresClocking,
                'benefits' => 'Standard health and pension benefits',
                'accountId' => $account->id,
                'contractTypeId' => $contractType->id,
                'employmentPolicies' => 'Company handbook and leave policy apply',
                'contractAgreementPath' => 'contracts/' . $employee->code . '.pdf',
                'departmentId' => $department->id,
                'worksiteId' => $worksite->id,
                'defaultPayPeriodGroupId' => $employeePayPeriodGroup->id,
            ];

            $existing = EmploymentDetail::query()
                ->where('employeeId', $employee->id)
                ->where('isActive', true)
                ->first();

            if ($existing) {
                $existing->update($payload);
                $employmentDetail = $existing->fresh();
            } else {
                $employmentDetail = EmploymentDetail::create(array_merge($payload, [
                    'id' => (string) Str::uuid(),
                    'employeeId' => $employee->id,
                ]));
            }

            $standardWeeklyHours = fake()->randomElement([40, 45, 39.5]);
            $compensationPayload = [
                'effectiveDate' => $startDate,
                'endDate' => $endDate,
                'isActive' => true,
                'compensationMethod' => $compensationMethod,
                'requiresClocking' => $requiresClocking,
                'standardWeeklyHours' => $standardWeeklyHours,
                'hourlyRate' => str_starts_with($compensationMethod, 'HOURLY')
                    ? $hourlyRate
                    : round($yearlyRate / ($standardWeeklyHours * 52), 2),
                'yearlyRate' => str_starts_with($compensationMethod, 'BASE')
                    ? $yearlyRate
                    : round($hourlyRate * $standardWeeklyHours * 52, 2),
                'payscale' => 'Scale A',
                'payscalePoint' => 'A1',
                'reasonType' => 'INITIAL',
                'reasonNote' => 'Seeded compensation record.',
            ];

            $existingCompensation = EmployeeCompensation::query()
                ->where('employmentDetailId', $employmentDetail->id)
                ->where('isActive', true)
                ->first();

            $compensationPayload['employmentDetailId'] = $employmentDetail->id;

            if ($existingCompensation) {
                $existingCompensation->update($compensationPayload);
            } else {
                EmployeeCompensation::create(array_merge($compensationPayload, [
                    'id' => (string) Str::uuid(),
                    'employeeId' => $employee->id,
                ]));
            }

            $employee->update([
                'employmentStatusId' => $employmentStatus->id,
                'employeeStatusId' => $employeeStatus->id,
            ]);
        }
    }
}
