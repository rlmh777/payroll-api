<?php

namespace App\Modules\Hr\Services\Appraisal;

use App\Models\Company;
use App\Models\HrSetting;
use App\Models\User;
use App\Modules\Hr\Models\EmployeeAppraisal;
use App\Modules\Hr\Models\HrTemplate;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use App\Modules\Workflow\Services\PipelineEngine;
use App\Support\ConfiguredMail;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

class AppraisalNotificationService
{
    public const PENDING_KEY = 'email.appraisal_pending';

    public const REMINDER_KEY = 'email.appraisal_reminder';

    public const COMPLETED_KEY = 'email.appraisal_completed';

    public const REJECTED_KEY = 'email.appraisal_rejected';

    public function __construct(
        private readonly ConfiguredMail $mail,
        private readonly PipelineEngine $pipelineEngine,
        private readonly AppraisalAssigneeResolver $assignees,
        private readonly EmployeeCompensationResolver $compensationResolver,
    ) {}

    public function notifyEmployee(EmployeeAppraisal $appraisal): void
    {
        $appraisal->loadMissing('employee.user');
        $user = $appraisal->employee?->user;
        if (! $user) {
            return;
        }

        $this->sendToUsers($appraisal, collect([$user]), self::PENDING_KEY, 'Your appraisal');
    }

    public function notifyCurrentStep(EmployeeAppraisal $appraisal): void
    {
        $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_APPRAISAL, (string) $appraisal->id);
        $users = $this->assignees->usersForCurrentStep($appraisal, $instance);
        $this->sendToUsers($appraisal, $users, self::PENDING_KEY, $instance?->currentStep?->label);

        $appraisal->last_notified_at = now();
        $appraisal->last_reminded_at = now();
        $appraisal->save();
    }

    public function notifyOutcome(EmployeeAppraisal $appraisal): void
    {
        $key = $appraisal->status === EmployeeAppraisal::STATUS_REJECTED
            ? self::REJECTED_KEY
            : self::COMPLETED_KEY;

        $recipients = $this->outcomeRecipients($appraisal);
        $this->sendToUsers($appraisal, $recipients, $key, $appraisal->status);
    }

    /**
     * @return array{sent: int, skipped: int, errors: int}
     */
    public function sendDueReminders(): array
    {
        $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
        $settings = HrSetting::current();
        if (! $settings->appraisalReminderEnabled()) {
            return $stats;
        }

        if (! $this->mail->isReady()) {
            return $stats;
        }

        $interval = $settings->appraisalReminderInterval();
        $threshold = $interval['unit'] === 'weeks'
            ? now()->subWeeks($interval['value'])
            : now()->subDays($interval['value']);

        $appraisals = EmployeeAppraisal::query()
            ->with(['employee.person', 'employee.user'])
            ->where('status', EmployeeAppraisal::STATUS_PENDING)
            ->get();

        foreach ($appraisals as $appraisal) {
            $outcome = $this->remindIfDue($appraisal, $threshold);
            $stats[$outcome]++;
        }

        return $stats;
    }

    /**
     * Email the current assignees when the reminder interval has elapsed.
     * No-op when reminders are disabled or mail is not ready.
     *
     * @return 'sent'|'skipped'|'errors'
     */
    public function remindIfDue(EmployeeAppraisal $appraisal, ?Carbon $threshold = null): string
    {
        $settings = HrSetting::current();
        if (! $settings->appraisalReminderEnabled() || ! $this->mail->isReady()) {
            return 'skipped';
        }

        if ($appraisal->status !== EmployeeAppraisal::STATUS_PENDING) {
            return 'skipped';
        }

        if ($threshold === null) {
            $interval = $settings->appraisalReminderInterval();
            $threshold = $interval['unit'] === 'weeks'
                ? now()->subWeeks($interval['value'])
                : now()->subDays($interval['value']);
        }

        $last = $appraisal->last_reminded_at ?? $appraisal->last_notified_at;
        if ($last instanceof Carbon && $last->gt($threshold)) {
            return 'skipped';
        }

        $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_APPRAISAL, (string) $appraisal->id);
        $users = $this->assignees->usersForCurrentStep($appraisal, $instance);
        if ($users->isEmpty()) {
            return 'skipped';
        }

        $sent = $this->sendToUsers($appraisal, $users, self::REMINDER_KEY, $instance?->currentStep?->label);
        if ($sent < 1) {
            return 'errors';
        }

        $appraisal->last_reminded_at = now();
        $appraisal->save();

        return 'sent';
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function sendToUsers(EmployeeAppraisal $appraisal, Collection $users, string $templateKey, ?string $stepLabel): int
    {
        $template = HrTemplate::query()
            ->where('system_key', $templateKey)
            ->where('is_active', true)
            ->first();
        if (! $template || ! $this->mail->isReady()) {
            return 0;
        }

        $sent = 0;
        foreach ($users as $user) {
            if (! $user->email) {
                continue;
            }
            $tokens = $this->tokens($appraisal, $user, $stepLabel);
            try {
                $this->mail->sendHtml(
                    $user->email,
                    $this->replaceTokens((string) $template->subject, $tokens, escape: false),
                    $this->replaceTokens((string) $template->body, $tokens, escape: true),
                );
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $sent;
    }

    /**
     * @return Collection<int, User>
     */
    private function outcomeRecipients(EmployeeAppraisal $appraisal): Collection
    {
        $appraisal->loadMissing(['employee.user', 'initiatedBy']);
        $users = collect();
        if ($appraisal->employee?->user) {
            $users->push($appraisal->employee->user);
        }
        if ($appraisal->initiatedBy) {
            $users->push($appraisal->initiatedBy);
        }

        return $users->unique('id')->values();
    }

    /**
     * @return array<string, string>
     */
    public function tokens(EmployeeAppraisal $appraisal, User $recipient, ?string $stepLabel): array
    {
        $company = Company::query()->first();
        $frontend = (string) config('app.frontend_url', 'http://localhost:9000');
        $actionUrl = $frontend.'/appraisals/'.$appraisal->id;

        return [
            '{{recipient_name}}' => $recipient->name ?: 'there',
            '{{employee_name}}' => $appraisal->employeeDisplayName(),
            '{{employee_code}}' => (string) ($appraisal->employee?->code ?? ''),
            '{{salary}}' => $this->compensationResolver->formattedSalaryForEmployee(
                $appraisal->employee_id ? (string) $appraisal->employee_id : null,
            ),
            '{{period_label}}' => (string) $appraisal->period_label,
            '{{step_label}}' => (string) ($stepLabel ?: 'the current step'),
            '{{action_url}}' => $actionUrl,
            '{{company_name}}' => (string) ($company?->legalName ?: $company?->alias ?: config('app.name')),
            '{{decision_summary}}' => $this->decisionSummary($appraisal),
            '{{hr_recommendations}}' => $this->hrRecommendations($appraisal),
            '{{current_date}}' => now()->toFormattedDateString(),
        ];
    }

    private function decisionSummary(EmployeeAppraisal $appraisal): string
    {
        return match ($appraisal->status) {
            EmployeeAppraisal::STATUS_APPROVED => 'The appraisal was approved.',
            EmployeeAppraisal::STATUS_REJECTED => 'The appraisal was declined.',
            EmployeeAppraisal::STATUS_CANCELLED => 'The appraisal was cancelled.',
            default => 'The appraisal is in progress.',
        };
    }

    private function hrRecommendations(EmployeeAppraisal $appraisal): string
    {
        $parts = [];
        if ($appraisal->hr_renew_contract !== null) {
            $parts[] = $appraisal->hr_renew_contract
                ? 'Recommend renewing the contract.'
                : 'Do not renew the contract.';
        }
        if ($appraisal->hr_increment_approved !== null) {
            if ($appraisal->hr_increment_approved) {
                $value = $appraisal->hr_increment_value !== null
                    ? rtrim(rtrim(number_format((float) $appraisal->hr_increment_value, 2, '.', ''), '0'), '.')
                    : '';
                $type = $appraisal->hr_increment_type === EmployeeAppraisal::INCREMENT_PERCENT ? '%' : '';
                $parts[] = 'Increment approved'.($value !== '' ? ': '.$value.$type : '.');
            } else {
                $parts[] = 'No increment.';
            }
        }
        if (filled($appraisal->hr_notes)) {
            $parts[] = 'HR notes: '.$appraisal->hr_notes;
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function replaceTokens(string $text, array $tokens, bool $escape): string
    {
        $replacements = [];
        foreach ($tokens as $token => $value) {
            $replacements[$token] = $escape
                ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : $value;
        }

        return strtr($text, $replacements);
    }
}
