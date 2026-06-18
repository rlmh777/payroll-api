<?php

namespace App\Jobs;

use App\Services\Attendance\TimesheetProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessClockingLogsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(private readonly array $filters = [])
    {
    }

    public function handle(TimesheetProcessingService $processingService): void
    {
        $result = $processingService->process($this->filters);

        Log::info('Clocking logs processed.', $result);
    }
}

