<?php

namespace App\Modules\Hr\Services\Appraisal;

use App\Models\EmploymentDetail;
use App\Modules\Hr\Models\EmployeeAppraisal;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class AppraisalScheduleService
{
    public function __construct(
        private readonly AppraisalWorkflowService $workflow,
        private readonly AppraisalNotificationService $notifications,
    ) {}

    /**
     * @return array{started: int, skipped: int}
     */
    public function startDue(): array
    {
        $stats = ['started' => 0, 'skipped' => 0];
        $contracts = EmploymentDetail::query()
            ->where('isActive', true)
            ->orderBy('startDate')
            ->get();

        foreach ($contracts as $contract) {
            $milestone = $this->milestone($contract);
            if (! $milestone || now()->startOfDay()->lt($milestone->copy()->subMonths(3))) {
                $stats['skipped']++;

                continue;
            }

            $existing = $this->workflow->existingForPeriod(
                (string) $contract->employeeId,
                $milestone->toDateString(),
                $milestone->toDateString(),
                null,
            );
            if ($existing) {
                $this->notifications->remindIfDue($existing);
                $stats['skipped']++;

                continue;
            }

            try {
                $this->workflow->start([
                    'employee_id' => (string) $contract->employeeId,
                    'period_label' => 'Appraisal due '.$milestone->toFormattedDateString(),
                    'period_end' => $milestone->toDateString(),
                    'employment_detail_id' => (string) $contract->id,
                    'scheduled_for' => $milestone->toDateString(),
                    'system' => true,
                ], null);
                $stats['started']++;
            } catch (ValidationException) {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    private function milestone(EmploymentDetail $contract): ?Carbon
    {
        $dates = collect([$contract->appraisalDate, $contract->endDate])
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->startOfDay());

        return $dates->sort()->first();
    }
}
