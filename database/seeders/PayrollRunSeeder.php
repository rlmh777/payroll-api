<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Allowance;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\HistoricalEmployeeAllowance;
use App\Models\HistoricalEmployeeDeduction;
use App\Models\PayPeriodGroup;
use App\Models\PayPeriodSchedule;
use App\Models\PayrollRun;
use App\Models\Timesheet;
use App\Models\User;
use App\Modules\Hr\Services\Attendance\TimesheetCompensationPayService;
use App\Modules\Payroll\Services\PayrollRunFrequencyResolver;
use App\Modules\Payroll\Services\PayrollTimesheetScopeService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class PayrollRunSeeder extends Seeder
{
    public function run(): void
    {
        $timesheetEnd = Carbon::today()->subDay()->startOfDay();
        $timesheetStart = Carbon::today()->subWeeks(2)->startOfDay();

        $schedule = $this->resolvePayPeriodSchedule($timesheetStart, $timesheetEnd);
        if (!$schedule) {
            $this->command?->warn('PayrollRunSeeder skipped: no pay period schedule overlaps seeded timesheets.');

            return;
        }

        $schedule->loadMissing('payPeriodGroup');
        $scheduleStart = Carbon::parse($schedule->start_date)->startOfDay();
        $scheduleEnd = Carbon::parse($schedule->end_date)->startOfDay();
        $payPeriodGroupId = (string) $schedule->pay_period_group_id;
        $frequencyId = app(PayrollRunFrequencyResolver::class)->resolveFromGroup($schedule->payPeriodGroup);
        $approverId = $this->resolveApproverEmployeeId();

        $timesheets = Timesheet::query()
            ->whereDate('date', '>=', $scheduleStart->toDateString())
            ->whereDate('date', '<=', $scheduleEnd->toDateString())
            ->get();

        if ($timesheets->isNotEmpty()) {
            app(TimesheetCompensationPayService::class)->syncPayContextForCollection($timesheets);
        }

        $approvedCount = app(PayrollTimesheetScopeService::class)
            ->baseQuery($scheduleStart, $scheduleEnd, $payPeriodGroupId, $frequencyId)
            ->update([
                'approvalStatus' => 'APPROVED',
                'approvedBy' => $approverId,
                'approvedAt' => $scheduleEnd->copy()->setTime(18, 0)->format('Y-m-d H:i:s'),
                'remarks' => null,
            ]);

        $employeeIds = app(PayrollTimesheetScopeService::class)->employeeIds(
            $scheduleStart,
            $scheduleEnd,
            $payPeriodGroupId,
            $frequencyId,
        );

        if ($employeeIds === []) {
            $this->command?->warn(sprintf(
                'PayrollRunSeeder skipped: no in-scope employees for %s (%s - %s).',
                $schedule->payPeriodGroup?->name ?? 'pay period',
                $scheduleStart->toDateString(),
                $scheduleEnd->toDateString(),
            ));

            return;
        }

        $payrollRun = PayrollRun::query()
            ->where('pay_period_schedule_id', $schedule->id)
            ->where('status', 'draft')
            ->first();

        if (!$payrollRun) {
            $existingPosted = PayrollRun::query()
                ->where('pay_period_schedule_id', $schedule->id)
                ->where('status', 'posted')
                ->exists();

            if ($existingPosted) {
                $this->command?->warn(sprintf(
                    'PayrollRunSeeder skipped: pay period %s - %s already has a posted payroll run.',
                    $scheduleStart->toDateString(),
                    $scheduleEnd->toDateString(),
                ));

                return;
            }

            $payrollRun = PayrollRun::create([
                'pay_period_schedule_id' => $schedule->id,
                'payrate_frequency_id' => $frequencyId,
                'status' => 'draft',
            ]);
        }

        HistoricalEmployeeAllowance::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->delete();
        HistoricalEmployeeDeduction::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->delete();

        [$allowanceCount, $deductionCount] = $this->seedAllowancesAndDeductions(
            $payrollRun,
            $employeeIds,
        );

        $this->command?->info(sprintf(
            'PayrollRunSeeder prepared draft payroll run for %s (%s - %s): %d approved timesheets, %d employees, %d allowances, %d deductions.',
            $schedule->payPeriodGroup?->name ?? 'pay period',
            $scheduleStart->toDateString(),
            $scheduleEnd->toDateString(),
            $approvedCount,
            count($employeeIds),
            $allowanceCount,
            $deductionCount,
        ));
    }

    private function resolvePayPeriodSchedule(Carbon $timesheetStart, Carbon $timesheetEnd): ?PayPeriodSchedule
    {
        $defaultGroupId = PayPeriodGroup::query()
            ->where('isDefault', true)
            ->value('id');

        /** @var Collection<int, PayPeriodSchedule> $candidates */
        $candidates = PayPeriodSchedule::query()
            ->with('payPeriodGroup')
            ->whereDate('start_date', '<=', $timesheetEnd->toDateString())
            ->whereDate('end_date', '>=', $timesheetStart->toDateString())
            ->get()
            ->sortByDesc(function (PayPeriodSchedule $schedule) use ($timesheetStart, $timesheetEnd, $defaultGroupId) {
                $overlapStart = Carbon::parse($schedule->start_date)->max($timesheetStart);
                $overlapEnd = Carbon::parse($schedule->end_date)->min($timesheetEnd);
                $overlapDays = $overlapStart->diffInDays($overlapEnd) + 1;
                $defaultBonus = $defaultGroupId && (string) $schedule->pay_period_group_id === (string) $defaultGroupId
                    ? 1000
                    : 0;

                return $defaultBonus + $overlapDays;
            })
            ->values();

        return $candidates->first();
    }

    private function resolveApproverEmployeeId(): ?string
    {
        $supervisorUserId = User::query()->where('email', 'supervisor@example.com')->value('id');
        if ($supervisorUserId) {
            $supervisorEmployeeId = Employee::query()->where('user_id', $supervisorUserId)->value('id');
            if ($supervisorEmployeeId) {
                return (string) $supervisorEmployeeId;
            }
        }

        $employeeId = Employee::query()
            ->orderByPersonName('lastName', 'asc')
            ->orderBy('person.firstName', 'asc')
            ->value('employee.id');

        return $employeeId ? (string) $employeeId : null;
    }

    /**
     * @param list<string> $employeeIds
     * @return array{0:int,1:int}
     */
    private function seedAllowancesAndDeductions(
        PayrollRun $payrollRun,
        array $employeeIds,
    ): array {
        $transportAllowance = Allowance::query()->firstOrCreate(
            ['name' => 'Transport Allowance'],
            [
                'isTaxable' => true,
                'isSocialSecurityDeductable' => true,
                'note' => 'Seeded transport allowance for payroll demo.',
                'defaultAmount' => 0,
            ],
        );
        $mealAllowance = Allowance::query()->firstOrCreate(
            ['name' => 'Meal Allowance'],
            [
                'isTaxable' => false,
                'isSocialSecurityDeductable' => false,
                'note' => 'Seeded non-taxable meal allowance for payroll demo.',
                'defaultAmount' => 0,
            ],
        );
        $loanDeductionType = DeductionType::query()->firstOrCreate(
            ['name' => 'Staff Loan'],
            [
                'note' => 'Seeded staff loan deduction for payroll demo.',
                'defaultAmount' => 0,
            ],
        );
        $uniformDeductionType = DeductionType::query()->firstOrCreate(
            ['name' => 'Uniform Deduction'],
            [
                'note' => 'Seeded uniform deduction for payroll demo.',
                'defaultAmount' => 0,
            ],
        );

        $allowanceAccount = Account::query()->where('code1', '6106')->first();
        $deductionAccount = Account::query()->where('code1', '6199')->first();
        $allowanceCount = 0;
        $deductionCount = 0;

        foreach ($employeeIds as $index => $employeeId) {
            $transportAmount = round(75 + ($index % 5) * 15, 2);
            HistoricalEmployeeAllowance::query()->create([
                'employee_id' => $employeeId,
                'allowance_id' => $transportAllowance->id,
                'payroll_run_id' => $payrollRun->id,
                'account_id' => $allowanceAccount?->id,
                'amount' => $transportAmount,
                'taxableAmount' => $transportAmount,
                'ssSubjectAmount' => $transportAmount,
                'note' => 'Seeded transport allowance',
            ]);
            $allowanceCount++;

            if ($index % 2 === 0) {
                $mealAmount = round(35 + ($index % 3) * 10, 2);
                HistoricalEmployeeAllowance::query()->create([
                    'employee_id' => $employeeId,
                    'allowance_id' => $mealAllowance->id,
                    'payroll_run_id' => $payrollRun->id,
                    'account_id' => $allowanceAccount?->id,
                    'amount' => $mealAmount,
                    'taxableAmount' => 0,
                    'ssSubjectAmount' => 0,
                    'note' => 'Seeded meal allowance',
                ]);
                $allowanceCount++;
            }

            $loanAmount = round(40 + ($index % 4) * 12.5, 2);
            HistoricalEmployeeDeduction::query()->create([
                'employee_id' => $employeeId,
                'deduction_type_id' => $loanDeductionType->id,
                'payment_to_id' => null,
                'payroll_run_id' => $payrollRun->id,
                'account_id' => $deductionAccount?->id,
                'amount' => $loanAmount,
                'carryForwardShortfall' => 0,
                'priority' => 1,
                'note' => 'Seeded staff loan repayment',
            ]);
            $deductionCount++;

            if ($index % 3 === 0) {
                $uniformAmount = round(20 + ($index % 2) * 5, 2);
                HistoricalEmployeeDeduction::query()->create([
                    'employee_id' => $employeeId,
                    'deduction_type_id' => $uniformDeductionType->id,
                    'payment_to_id' => null,
                    'payroll_run_id' => $payrollRun->id,
                    'account_id' => $deductionAccount?->id,
                    'amount' => $uniformAmount,
                    'carryForwardShortfall' => 0,
                    'priority' => 2,
                    'note' => 'Seeded uniform deduction',
                ]);
                $deductionCount++;
            }
        }

        return [$allowanceCount, $deductionCount];
    }
}
