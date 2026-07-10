<?php

namespace App\Console\Commands;

use App\Services\Attendance\TimesheetProcessingScheduler;
use App\Services\Attendance\TimesheetProcessingService;
use Illuminate\Console\Command;

class ProcessTimesheetsCommand extends Command
{
    protected $signature = 'timesheets:process
        {--start-date= : Inclusive start date (Y-m-d)}
        {--end-date= : Inclusive end date (Y-m-d)}
        {--sync : Run immediately instead of dispatching to the queue}';

    protected $description = 'Process clocking logs and schedules into timesheets';

    public function handle(
        TimesheetProcessingScheduler $scheduler,
        TimesheetProcessingService $processingService,
    ): int {
        $filters = array_filter([
            'startDate' => $this->option('start-date'),
            'endDate' => $this->option('end-date'),
        ], fn ($value) => is_string($value) && $value !== '');

        if ($filters === []) {
            $filters = $scheduler->dailyFilters();
        } elseif (!isset($filters['startDate'], $filters['endDate'])) {
            $this->error('Provide both --start-date and --end-date, or omit both to use the configured daily window.');

            return self::FAILURE;
        }

        if ($this->option('sync')) {
            $result = $processingService->process($filters);
            $this->info(sprintf(
                'Processed %d timesheet row(s) from %s to %s.',
                $result['processedTimesheets'] ?? 0,
                $filters['startDate'],
                $filters['endDate'],
            ));

            return self::SUCCESS;
        }

        $scheduler->queue($filters);
        $this->info(sprintf(
            'Queued timesheet processing for %s to %s.',
            $filters['startDate'],
            $filters['endDate'],
        ));

        return self::SUCCESS;
    }
}
