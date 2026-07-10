<?php

namespace App\Console\Commands;

use App\Models\Timesheet;
use App\Services\Attendance\TimesheetOvertimeAllocator;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ReallocateTimesheetOvertimeCommand extends Command
{
    protected $signature = 'timesheets:reallocate-overtime {--from=} {--to=}';

    protected $description = 'Re-run overtime allocation for all employee weeks in the timesheet table';

    public function handle(TimesheetOvertimeAllocator $allocator): int
    {
        $query = Timesheet::query()->select(['employeeId', 'date']);

        if ($from = $this->option('from')) {
            $query->whereDate('date', '>=', Carbon::parse($from)->toDateString());
        }

        if ($to = $this->option('to')) {
            $query->whereDate('date', '<=', Carbon::parse($to)->toDateString());
        }

        $weekKeys = [];

        foreach ($query->get() as $timesheet) {
            if (!$timesheet->employeeId || !$timesheet->date) {
                continue;
            }

            $weekStart = Carbon::parse($timesheet->date)->startOfWeek(Carbon::MONDAY)->toDateString();
            $weekKeys[(string) $timesheet->employeeId.'|'.$weekStart] = [
                (string) $timesheet->employeeId,
                $weekStart,
            ];
        }

        $count = count($weekKeys);
        $this->info("Reallocating overtime for {$count} employee-week(s)...");

        foreach ($weekKeys as [$employeeId, $weekStart]) {
            $allocator->redistributeEmployeeWeek($employeeId, Carbon::parse($weekStart));
        }

        $this->info('Overtime reallocation complete.');

        return self::SUCCESS;
    }
}
