<?php

namespace App\Modules\Payroll\Services;

use App\Models\Account;
use App\Models\Allowance;
use App\Models\Employee;
use App\Models\HistoricalEmployeeAllowance;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayrollAllowanceImportService
{
    public function __construct(
        private readonly PayrollAllowanceAuthorizationService $authorization,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function preview(PayrollRun $payrollRun, array $rows): array
    {
        $this->authorization->assertUpcomingEditablePayrollRun($payrollRun);
        $payrollRun->loadMissing('payPeriodSchedule');

        $previewRows = [];
        $errorCount = 0;
        $allowanceTotal = 0.0;

        foreach ($rows as $index => $row) {
            $parsed = $this->validateRow($payrollRun, $row, $index + 1);
            $previewRows[] = $parsed;
            $errorCount += count($parsed['errors']);
            if (count($parsed['errors']) === 0) {
                $allowanceTotal = round($allowanceTotal + (float) $parsed['amount'], 2);
            }
        }

        return [
            'payrollRunId' => (string) $payrollRun->id,
            'recordCount' => count($previewRows),
            'errorCount' => $errorCount,
            'allowanceTotal' => round($allowanceTotal, 2),
            'rows' => $previewRows,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function confirm(PayrollRun $payrollRun, array $rows): array
    {
        $preview = $this->preview($payrollRun, $rows);

        if (($preview['errorCount'] ?? 0) > 0) {
            return $preview;
        }

        $inserted = DB::transaction(function () use ($preview, $payrollRun) {
            $count = 0;

            foreach ($preview['rows'] as $row) {
                HistoricalEmployeeAllowance::query()->create([
                    'employee_id' => $row['employeeId'],
                    'allowance_id' => $row['allowanceId'],
                    'account_id' => $row['accountId'],
                    'payroll_run_id' => $payrollRun->id,
                    'allowance_date' => $row['allowanceDate'],
                    'quantity' => round((float) $row['quantity'], 4),
                    'unitAmount' => round((float) $row['unitAmount'], 2),
                    'amount' => round((float) $row['amount'], 2),
                    'taxableAmount' => round((float) $row['taxableAmount'], 2),
                    'ssSubjectAmount' => round((float) $row['ssSubjectAmount'], 2),
                    'note' => (string) ($row['note'] ?? ''),
                ]);
                $count++;
            }

            return $count;
        });

        return [
            ...$preview,
            'inserted' => $inserted,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function validateRow(PayrollRun $payrollRun, array $row, int $rowNumber): array
    {
        $employeeIdentifier = trim((string) ($row['employeeIdentifier'] ?? ''));
        $allowanceName = trim((string) ($row['allowanceName'] ?? ''));
        $accountCode = trim((string) ($row['accountCode'] ?? ''));
        $accountId = isset($row['accountId']) ? trim((string) $row['accountId']) : '';
        $allowanceDate = trim((string) ($row['allowanceDate'] ?? ''));
        $quantity = array_key_exists('quantity', $row) ? (float) $row['quantity'] : null;
        $unitAmount = array_key_exists('unitAmount', $row) ? (float) $row['unitAmount'] : null;
        $note = trim((string) ($row['note'] ?? ''));

        $errors = [];
        $employee = $employeeIdentifier !== '' ? $this->resolveEmployee($employeeIdentifier) : null;
        $allowance = $allowanceName !== '' ? $this->resolveAllowance($allowanceName) : null;
        $account = $this->resolveAccount($accountCode, $accountId !== '' ? $accountId : null);

        if ($employeeIdentifier === '') {
            $errors[] = 'Employee ID is required.';
        } elseif (! $employee) {
            $errors[] = "Employee '{$employeeIdentifier}' was not found.";
        }

        if ($allowanceName === '') {
            $errors[] = 'Other Payment is required.';
        } elseif (! $allowance) {
            $errors[] = "Other Payment '{$allowanceName}' was not found.";
        }

        if ($accountCode === '' && $accountId === '') {
            $errors[] = 'Account code is required.';
        } elseif (! $account) {
            $errors[] = $accountCode === ''
                ? 'Account was not found.'
                : "Account code '{$accountCode}' was not found.";
        }

        if ($allowanceDate === '') {
            $errors[] = 'Date is required.';
        } else {
            $schedule = $payrollRun->payPeriodSchedule;
            if (! $schedule) {
                $errors[] = 'The payroll run does not have a pay period schedule.';
            } else {
                $date = strtotime($allowanceDate);
                $start = strtotime((string) $schedule->start_date);
                $end = strtotime((string) $schedule->end_date);
                if ($date === false || $date < $start || $date > $end) {
                    $errors[] = 'Date must fall within the selected pay period.';
                }
            }
        }

        if ($quantity === null || $quantity <= 0) {
            $errors[] = 'Quantity must be greater than zero.';
        }

        if ($unitAmount === null || $unitAmount < 0) {
            $errors[] = 'Unit amount must be zero or greater.';
        }

        $amount = ($quantity !== null && $unitAmount !== null)
            ? round($quantity * $unitAmount, 2)
            : 0.0;

        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        }

        $isTaxable = (bool) ($allowance?->isTaxable ?? true);
        $isSsSubject = (bool) ($allowance?->isSocialSecurityDeductable ?? true);

        $firstName = (string) ($employee?->firstName ?? '');
        $lastName = (string) ($employee?->lastName ?? '');
        $employeeName = trim($lastName.($lastName && $firstName ? ', ' : '').$firstName);

        return [
            'rowNumber' => $rowNumber,
            'employeeIdentifier' => $employeeIdentifier,
            'employeeId' => $employee?->id ? (string) $employee->id : null,
            'employeeName' => $employeeName !== '' ? $employeeName : ($row['employeeName'] ?? null),
            'allowanceName' => $allowanceName,
            'allowanceId' => $allowance?->id ? (string) $allowance->id : null,
            'accountCode' => $accountCode !== '' ? $accountCode : ($account?->code1 ?? null),
            'accountId' => $account?->id ? (string) $account->id : null,
            'accountName' => $account?->name,
            'allowanceDate' => $allowanceDate,
            'quantity' => $quantity,
            'unitAmount' => $unitAmount,
            'amount' => $amount,
            'taxableAmount' => $isTaxable ? $amount : 0.0,
            'ssSubjectAmount' => $isSsSubject ? $amount : 0.0,
            'note' => $note,
            'errors' => $errors,
        ];
    }

    private function resolveEmployee(string $identifier): ?Employee
    {
        return Employee::query()
            ->where(function ($query) use ($identifier) {
                if (Str::isUuid($identifier)) {
                    $query->where('id', $identifier);
                }

                $query
                    ->orWhere('code', $identifier)
                    ->orWhere('internalId1', $identifier)
                    ->orWhere('internalId2', $identifier);
            })
            ->first();
    }

    private function resolveAllowance(string $name): ?Allowance
    {
        $normalized = mb_strtolower($name);

        return Allowance::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();
    }

    private function resolveAccount(string $code, ?string $accountId = null): ?Account
    {
        if ($accountId) {
            return Account::query()->find($accountId);
        }

        if ($code === '') {
            return null;
        }

        $normalized = mb_strtolower($code);

        return Account::query()
            ->whereRaw('LOWER("code1") = ?', [$normalized])
            ->orWhereRaw('LOWER("code2") = ?', [$normalized])
            ->orWhereRaw('LOWER("name") = ?', [$normalized])
            ->first();
    }
}
