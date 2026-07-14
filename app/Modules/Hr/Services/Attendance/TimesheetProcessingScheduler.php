<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Jobs\ProcessClockingLogsJob;
use Carbon\Carbon;

class TimesheetProcessingScheduler
{
    /**
     * @return array{startDate:string, endDate:string}
     */
    public function dailyFilters(?Carbon $throughDate = null): array
    {
        $timezone = config('app.timezone', 'UTC');
        $through = ($throughDate ?? Carbon::now($timezone))
            ->copy()
            ->subDay()
            ->startOfDay();
        $lookbackDays = max(0, (int) config('attendance.timesheet_processing.lookback_days', 7));
        $start = $through->copy()->subDays($lookbackDays)->startOfDay();

        return [
            'startDate' => $start->toDateString(),
            'endDate' => $through->toDateString(),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function queue(array $filters = []): void
    {
        if ($filters === []) {
            $filters = $this->dailyFilters();
        }

        ProcessClockingLogsJob::dispatch($filters);
    }

    public function queueAfterIngest(int $insertedCount, array $rows = []): void
    {
        if ($insertedCount <= 0 || !config('attendance.timesheet_processing.queue_after_ingest', true)) {
            return;
        }

        $range = $this->punchDateRangeFromRows($rows);

        $this->queue($range ?? $this->dailyFilters());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{startDate:string, endDate:string}|null
     */
    private function punchDateRangeFromRows(array $rows): ?array
    {
        $dates = [];

        foreach ($rows as $row) {
            $value = $row['punchDateTime'] ?? null;
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            try {
                $dates[] = Carbon::parse((string) $value, config('app.timezone', 'UTC'))->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        if ($dates === []) {
            return null;
        }

        sort($dates);

        return [
            'startDate' => $dates[0],
            'endDate' => $dates[array_key_last($dates)],
        ];
    }
}
