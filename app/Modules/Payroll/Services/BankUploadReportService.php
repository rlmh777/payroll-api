<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Payroll;
use App\Models\PayrollRun;
use InvalidArgumentException;

class BankUploadReportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(PayrollRun $payrollRun): array
    {
        $payrollRun->loadMissing(['payPeriodSchedule.payPeriodGroup']);

        if (strtolower((string) $payrollRun->status) !== 'posted') {
            throw new InvalidArgumentException('Bank upload can only be generated for posted payroll runs.');
        }

        if (empty($payrollRun->payroll_number)) {
            $payrollRun->payroll_number = PayrollRun::nextPayrollNumber();
            $payrollRun->save();
        }

        $company = Company::query()->orderBy('id')->first();
        $branchNumber = trim((string) ($company?->bankBranchNumber ?? ''));
        if ($branchNumber === '') {
            $branchNumber = '000';
        }

        $payrollNumberFormatted = $payrollRun->payrollNumberFormatted;
        $payrollNumberLabel = 'PAYROLL # '.$payrollNumberFormatted;

        $rows = Payroll::query()
            ->with(['employee.person'])
            ->where('payroll_run_id', $payrollRun->id)
            ->whereNotNull('accountNumber')
            ->where('accountNumber', '!=', '')
            ->where('netSalary', '>', 0)
            ->get()
            ->map(function (Payroll $payroll) use ($branchNumber, $payrollNumberLabel) {
                $employee = $payroll->employee;
                $firstName = trim((string) ($employee?->firstName ?? ''));
                $lastName = trim((string) ($employee?->lastName ?? ''));
                $name = trim(implode(' ', array_filter([$firstName, $lastName]))) ?: 'Unknown employee';
                $accountNumber = preg_replace('/\s+/', '', (string) $payroll->accountNumber) ?? '';
                $netPay = round((float) $payroll->netSalary, 2);

                return [
                    'transactionType' => 'CR',
                    'paymentType' => 'SAL',
                    'branchNumber' => $branchNumber,
                    'default1' => 0,
                    'default2' => 0,
                    'default3' => 0,
                    'accountNumber' => $accountNumber,
                    'employeeName' => $name,
                    'netPay' => $netPay,
                    'payrollNumberLabel' => $payrollNumberLabel,
                    'csvLine' => implode(',', [
                        'CR',
                        'SAL',
                        $branchNumber,
                        '0',
                        '0',
                        '0',
                        $accountNumber,
                        $this->escapeCsvField($name),
                        number_format($netPay, 2, '.', ''),
                        $payrollNumberLabel,
                    ]),
                ];
            })
            ->sortBy('employeeName', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $schedule = $payrollRun->payPeriodSchedule;
        $rowCount = count($rows);
        $totalNetPay = round(array_sum(array_column($rows, 'netPay')), 2);
        $companyName = trim((string) ($company?->legalName ?? $company?->alias ?? ''));
        $generatedOn = now()->format('Y-m-d');

        $csvLines = [
            $this->escapeCsvField($companyName),
            '',
            implode(',', [
                $generatedOn,
                '',
                (string) $rowCount,
                number_format($totalNetPay, 2, '.', ''),
            ]),
            '',
            '',
            ...array_map(fn (array $row) => $row['csvLine'], $rows),
        ];

        return [
            'payrollRunId' => $payrollRun->id,
            'payrollNumber' => $payrollRun->payroll_number,
            'payrollNumberFormatted' => $payrollNumberFormatted,
            'payrollNumberLabel' => $payrollNumberLabel,
            'payPeriodGroupId' => $schedule?->pay_period_group_id,
            'payPeriodGroupName' => $schedule?->payPeriodGroup?->name,
            'payPeriodStartDate' => optional($schedule?->start_date)?->toDateString(),
            'payPeriodEndDate' => optional($schedule?->end_date)?->toDateString(),
            'companyName' => $companyName,
            'generatedOn' => $generatedOn,
            'branchNumber' => $branchNumber,
            'rows' => $rows,
            'totals' => [
                'rowCount' => $rowCount,
                'netPay' => $totalNetPay,
            ],
            'csv' => implode("\n", $csvLines)."\n",
        ];
    }

    private function escapeCsvField(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
