<?php

namespace App\Console\Commands;

use App\Modules\Payroll\Services\TimesheetPayrollLockService;
use Illuminate\Console\Command;

class ApplyPayrollTimesheetLocksCommand extends Command
{
    protected $signature = 'timesheets:apply-payroll-locks';

    protected $description = 'Advance the timesheet lock date for posted payroll runs whose lock deadline has passed';

    public function handle(TimesheetPayrollLockService $lockService): int
    {
        $result = $lockService->applyDueLocks();

        if (($result['skipped'] ?? null) === 'disabled') {
            $this->info('Timesheet auto-lock is disabled.');

            return self::SUCCESS;
        }

        $applied = (int) ($result['applied'] ?? 0);
        $this->info(sprintf(
            'Applied timesheet lock for %d posted payroll run(s).',
            $applied,
        ));

        return self::SUCCESS;
    }
}
