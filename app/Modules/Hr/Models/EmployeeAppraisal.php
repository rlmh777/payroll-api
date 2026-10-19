<?php

namespace App\Modules\Hr\Models;

use App\Models\PipelineTemplate;
use App\Models\User;
use App\Modules\Workflow\Services\PipelineEngine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAppraisal extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const INCREMENT_PERCENT = 'percent';

    public const INCREMENT_AMOUNT = 'amount';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'employee_id',
        'form_template_id',
        'form_response_id',
        'pipeline_template_id',
        'initiated_by_user_id',
        'period_label',
        'period_start',
        'period_end',
        'employment_detail_id',
        'scheduled_for',
        'next_appraisal_date',
        'status',
        'hr_renew_contract',
        'hr_increment_approved',
        'hr_increment_type',
        'hr_increment_value',
        'hr_notes',
        'accounts_notes',
        'submitted_at',
        'completed_at',
        'last_notified_at',
        'last_reminded_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'scheduled_for' => 'date',
        'next_appraisal_date' => 'date',
        'hr_renew_contract' => 'boolean',
        'hr_increment_approved' => 'boolean',
        'hr_increment_value' => 'decimal:2',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_notified_at' => 'datetime',
        'last_reminded_at' => 'datetime',
    ];

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function formTemplate(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    public function formResponse(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class, 'form_response_id');
    }

    public function pipelineTemplate(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplate::class, 'pipeline_template_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function employeeDisplayName(): string
    {
        $employee = $this->employee;
        if (! $employee) {
            return 'Employee';
        }

        $name = trim((string) $employee->firstName.' '.(string) $employee->lastName);

        return $name !== '' ? $name : (string) ($employee->code ?: 'Employee');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(?PipelineEngine $engine = null, array $flags = []): array
    {
        $this->loadMissing([
            'employee.person',
            'formTemplate',
            'formResponse',
            'pipelineTemplate',
            'initiatedBy',
        ]);

        $pipeline = null;
        if ($engine) {
            $instance = $engine->latestInstance(PipelineEngine::SUBJECT_APPRAISAL, (string) $this->id);
            $pipeline = $instance ? $engine->transformInstance($instance) : null;
        }

        $response = $this->formResponse;

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => [
                'id' => $this->employee?->id,
                'code' => $this->employee?->code,
                'firstName' => $this->employee?->firstName,
                'lastName' => $this->employee?->lastName,
                'name' => $this->employeeDisplayName(),
            ],
            'period_label' => $this->period_label,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'scheduled_for' => $this->scheduled_for?->toDateString(),
            'next_appraisal_date' => $this->next_appraisal_date?->toDateString(),
            'status' => $this->status,
            'form_template' => $this->formTemplate?->present(),
            'form_response' => $response?->present(),
            'pipeline' => $pipeline,
            'hr_renew_contract' => $this->hr_renew_contract,
            'hr_increment_approved' => $this->hr_increment_approved,
            'hr_increment_type' => $this->hr_increment_type,
            'hr_increment_value' => $this->hr_increment_value !== null ? (float) $this->hr_increment_value : null,
            'hr_notes' => $this->hr_notes,
            'accounts_notes' => $this->accounts_notes,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'initiated_by' => $this->initiatedBy ? [
                'id' => $this->initiatedBy->id,
                'name' => $this->initiatedBy->name,
            ] : null,
            'can_act' => (bool) ($flags['can_act'] ?? false),
            'can_edit_form' => (bool) ($flags['can_edit_form'] ?? false),
            'can_manage' => (bool) ($flags['can_manage'] ?? false),
            'is_supervisor_step' => (bool) ($flags['is_supervisor_step'] ?? false),
            'is_hr_step' => (bool) ($flags['is_hr_step'] ?? false),
            'is_accounts_step' => (bool) ($flags['is_accounts_step'] ?? false),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
