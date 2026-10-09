<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HrTemplate extends Model
{
    use HasUuids;

    public const CHANNEL_LETTER = 'letter';

    public const CHANNEL_EMAIL = 'email';

    protected $table = 'hr_templates';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'channel',
        'category',
        'system_key',
        'name',
        'subject',
        'body',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * @return list<string>
     */
    public static function channels(): array
    {
        return [self::CHANNEL_LETTER, self::CHANNEL_EMAIL];
    }

    /**
     * @return list<string>
     */
    public static function categoriesFor(string $channel): array
    {
        return $channel === self::CHANNEL_EMAIL
            ? ['leave', 'security', 'recruiting', 'contracts', 'other']
            : ['bank', 'embassy', 'other'];
    }

    /**
     * @return list<array{token: string, label: string}>
     */
    public static function placeholdersFor(string $channel): array
    {
        $shared = [
            ['token' => '{{employee_name}}', 'label' => 'Employee name'],
            ['token' => '{{employee_code}}', 'label' => 'Employee code'],
            ['token' => '{{job_title}}', 'label' => 'Job title'],
            ['token' => '{{department}}', 'label' => 'Department'],
            ['token' => '{{company_name}}', 'label' => 'Company name'],
            ['token' => '{{current_date}}', 'label' => 'Current date'],
        ];

        if ($channel === self::CHANNEL_EMAIL) {
            return [
                ...$shared,
                ['token' => '{{leave_type}}', 'label' => 'Leave type'],
                ['token' => '{{leave_dates}}', 'label' => 'Leave dates'],
                ['token' => '{{leave_status}}', 'label' => 'Leave status'],
                ['token' => '{{reset_link}}', 'label' => 'Password reset link'],
                ['token' => '{{vacancy_title}}', 'label' => 'Vacancy title'],
                ['token' => '{{application_status}}', 'label' => 'Application status'],
                ['token' => '{{recipient_name}}', 'label' => 'Recipient name'],
                ['token' => '{{recipient_role}}', 'label' => 'Recipient role'],
                ['token' => '{{contract_end_date}}', 'label' => 'Contract end date'],
                ['token' => '{{days_until_expiry}}', 'label' => 'Days until expiry'],
                ['token' => '{{reminder_window}}', 'label' => 'Reminder window'],
                ['token' => '{{contract_type}}', 'label' => 'Contract type'],
            ];
        }

        return [
            ...$shared,
            ['token' => '{{hire_date}}', 'label' => 'Hire date'],
            ['token' => '{{worksite}}', 'label' => 'Work site'],
            ['token' => '{{recipient_name}}', 'label' => 'Recipient name'],
            ['token' => '{{purpose}}', 'label' => 'Purpose of letter'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'category' => $this->category,
            'system_key' => $this->system_key,
            'name' => $this->name,
            'subject' => $this->subject,
            'body' => $this->body,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
