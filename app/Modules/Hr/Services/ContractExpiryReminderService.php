<?php

namespace App\Modules\Hr\Services;

use App\Models\Company;
use App\Models\ContractExpiryReminderSend;
use App\Models\EmployeeReporting;
use App\Models\EmploymentDetail;
use App\Models\HrSetting;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HrTemplate;
use App\Support\ConfiguredMail;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ContractExpiryReminderService
{
    public const TEMPLATE_KEY = 'email.contract_expiring';

    public function __construct(
        private readonly ConfiguredMail $mail,
    ) {
    }

    /**
     * @return array{sent: int, skipped: int, errors: int}
     */
    public function sendDueReminders(?CarbonInterface $asOf = null): array
    {
        $asOf = Carbon::parse($asOf ?? now())->startOfDay();
        $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];

        $settings = HrSetting::current();
        if (! $settings->contractExpiryEnabled()) {
            return $stats;
        }

        $template = HrTemplate::query()
            ->where('system_key', self::TEMPLATE_KEY)
            ->where('is_active', true)
            ->first();
        if (! $template) {
            return $stats;
        }

        if (! $this->mail->isReady()) {
            return $stats;
        }

        $offsets = $settings->contractExpiryOffsets();
        $contracts = EmploymentDetail::query()
            ->where('isActive', true)
            ->whereNotNull('endDate')
            ->whereDate('endDate', '>=', $asOf->toDateString())
            ->with([
                'employee.person',
                'employee.user',
                'employee.supervisor.person',
                'employee.supervisor.user',
                'jobTitle',
                'department',
                'contractType',
            ])
            ->get();

        foreach ($contracts as $contract) {
            foreach ($offsets as $offset) {
                if (! $this->offsetIsDue($contract->endDate, $offset, $asOf)) {
                    continue;
                }

                if ($this->alreadySent($contract, $offset)) {
                    $stats['skipped']++;
                    continue;
                }

                $recipients = $this->recipientsFor($contract);
                if ($recipients->isEmpty()) {
                    $stats['skipped']++;
                    continue;
                }

                $sentAny = false;
                foreach ($recipients as $recipient) {
                    try {
                        $tokens = $this->tokens($contract, $offset, $asOf, $recipient);
                        $this->mail->sendHtml(
                            $recipient['email'],
                            $this->replaceTokens((string) $template->subject, $tokens, escape: false),
                            $this->replaceTokens((string) $template->body, $tokens, escape: true),
                        );
                        $sentAny = true;
                        $stats['sent']++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $stats['errors']++;
                    }
                }

                if ($sentAny) {
                    $this->markSent($contract, $offset, $asOf);
                }
            }
        }

        return $stats;
    }

    /**
     * @param  array{value: int, unit: string}  $offset
     */
    public function offsetIsDue(mixed $endDate, array $offset, CarbonInterface $asOf): bool
    {
        if (! $endDate) {
            return false;
        }

        $end = Carbon::parse($endDate)->startOfDay();
        if ($end->lt($asOf)) {
            return false;
        }

        $reminderDate = $this->reminderDateFor($end, $offset);
        $daysLate = $reminderDate->diffInDays($asOf, false);

        return $daysLate >= 0 && $daysLate <= 1;
    }

    /**
     * @param  array{value: int, unit: string}  $offset
     */
    public function reminderDateFor(CarbonInterface $endDate, array $offset): Carbon
    {
        $value = max(1, (int) $offset['value']);
        $end = Carbon::parse($endDate)->startOfDay();

        return match ($offset['unit']) {
            'months' => $end->copy()->subMonthsNoOverflow($value),
            'weeks' => $end->copy()->subWeeks($value),
            default => $end->copy()->subDays($value),
        };
    }

    /**
     * @param  array{value: int, unit: string}  $offset
     */
    private function alreadySent(EmploymentDetail $contract, array $offset): bool
    {
        if (! Schema::hasTable('contract_expiry_reminder_sends')) {
            return false;
        }

        return ContractExpiryReminderSend::query()
            ->where('employment_detail_id', $contract->id)
            ->where('offset_value', (int) $offset['value'])
            ->where('offset_unit', $offset['unit'])
            ->exists();
    }

    /**
     * @param  array{value: int, unit: string}  $offset
     */
    private function markSent(EmploymentDetail $contract, array $offset, CarbonInterface $asOf): void
    {
        if (! Schema::hasTable('contract_expiry_reminder_sends')) {
            return;
        }

        ContractExpiryReminderSend::query()->firstOrCreate(
            [
                'employment_detail_id' => $contract->id,
                'offset_value' => (int) $offset['value'],
                'offset_unit' => $offset['unit'],
            ],
            [
                'sent_on' => $asOf->toDateString(),
            ],
        );
    }

    /**
     * @return Collection<int, array{email: string, name: string, role: string}>
     */
    public function recipientsFor(EmploymentDetail $contract): Collection
    {
        $employee = $contract->employee;
        $selfEmails = collect([
            strtolower((string) ($employee?->user?->email ?? '')),
            strtolower((string) ($employee?->email ?? '')),
        ])->filter()->values();

        /** @var array<string, array{email: string, name: string, role: string}> $byEmail */
        $byEmail = [];

        $add = function (string $email, string $name, string $role) use (&$byEmail, $selfEmails): void {
            $normalized = strtolower(trim($email));
            if ($normalized === '' || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            if ($selfEmails->contains($normalized)) {
                return;
            }
            if (isset($byEmail[$normalized]) && $this->rolePriority($byEmail[$normalized]['role']) <= $this->rolePriority($role)) {
                return;
            }
            $byEmail[$normalized] = [
                'email' => $normalized,
                'name' => $name !== '' ? $name : $normalized,
                'role' => $role,
            ];
        };

        if ($employee) {
            $supervisorIds = EmployeeReporting::query()
                ->where('subordinate_id', $employee->id)
                ->where('is_active', true)
                ->pluck('supervisor_id');

            if ($employee->supervisorId) {
                $supervisorIds->push($employee->supervisorId);
            }

            Employee::query()
                ->whereIn('id', $supervisorIds->unique()->filter()->all())
                ->with(['user', 'person'])
                ->get()
                ->each(function (Employee $supervisor) use ($add): void {
                    $email = (string) ($supervisor->user?->email ?: $supervisor->email ?: '');
                    $add($email, $this->personName($supervisor), 'Supervisor');
                });
        }

        User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['hr', 'gm']))
            ->with('roles')
            ->get()
            ->each(function (User $user) use ($add): void {
                $role = $user->hasRole('hr') ? 'HR' : 'General Manager';
                $add((string) $user->email, (string) ($user->name ?: $user->email), $role);
            });

        return collect(array_values($byEmail));
    }

    /**
     * @param  array{value: int, unit: string}  $offset
     * @param  array{email: string, name: string, role: string}  $recipient
     * @return array<string, string>
     */
    public function tokens(EmploymentDetail $contract, array $offset, CarbonInterface $asOf, array $recipient): array
    {
        $employee = $contract->employee;
        $end = Carbon::parse($contract->endDate)->startOfDay();
        $company = Company::query()->first();

        return [
            '{{recipient_name}}' => $recipient['name'],
            '{{recipient_role}}' => $recipient['role'],
            '{{employee_name}}' => $this->personName($employee),
            '{{employee_code}}' => (string) ($employee?->code ?? ''),
            '{{job_title}}' => (string) ($contract->jobTitle?->name ?? ''),
            '{{department}}' => (string) ($contract->department?->name ?? ''),
            '{{contract_type}}' => (string) ($contract->contractType?->name ?? ''),
            '{{contract_end_date}}' => $end->toFormattedDateString(),
            '{{days_until_expiry}}' => (string) $asOf->diffInDays($end),
            '{{reminder_window}}' => HrSetting::offsetLabel($offset),
            '{{company_name}}' => (string) ($company?->legalName ?: $company?->alias ?: config('app.name')),
            '{{current_date}}' => Carbon::parse($asOf)->toFormattedDateString(),
        ];
    }

    /**
     * @param  array<string, string>  $tokens
     */
    public function replaceTokens(string $text, array $tokens, bool $escape): string
    {
        $replacements = [];
        foreach ($tokens as $token => $value) {
            $replacements[$token] = $escape
                ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : $value;
        }

        return strtr($text, $replacements);
    }

    private function personName(?Employee $employee): string
    {
        if (! $employee) {
            return '';
        }

        return trim(implode(' ', array_filter([
            $employee->firstName,
            $employee->middleName,
            $employee->lastName,
        ])));
    }

    private function rolePriority(string $role): int
    {
        return match ($role) {
            'Supervisor' => 1,
            'HR' => 2,
            default => 3,
        };
    }
}
