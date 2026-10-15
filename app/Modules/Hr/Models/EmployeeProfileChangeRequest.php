<?php

namespace App\Modules\Hr\Models;

use App\Models\PipelineTemplate;
use App\Models\User;
use App\Modules\Workflow\Services\PipelineEngine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeProfileChangeRequest extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const CHANGEABLE_FIELDS = [
        'address1',
        'address2',
        'localityId',
        'phone',
        'email',
    ];

    protected $table = 'employee_profile_change_requests';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'employee_id',
        'submitted_by_user_id',
        'pipeline_template_id',
        'status',
        'current_values',
        'proposed_values',
        'reviewer_notes',
        'submitted_at',
        'completed_at',
        'applied_at',
    ];

    protected $casts = [
        'current_values' => 'array',
        'proposed_values' => 'array',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function pipelineTemplate(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplate::class, 'pipeline_template_id');
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
            'employee.person.locality',
            'pipelineTemplate',
            'submittedBy',
        ]);

        $pipeline = null;
        if ($engine) {
            $instance = $engine->latestInstance(PipelineEngine::SUBJECT_PROFILE_CHANGE, (string) $this->id);
            $pipeline = $instance ? $engine->transformInstance($instance) : null;
        }

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
            'status' => $this->status,
            'current_values' => $this->current_values ?? [],
            'proposed_values' => $this->proposed_values ?? [],
            'changes' => $this->changeRows(),
            'reviewer_notes' => $this->reviewer_notes,
            'pipeline' => $pipeline,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'submitted_by' => $this->submittedBy ? [
                'id' => $this->submittedBy->id,
                'name' => $this->submittedBy->name,
            ] : null,
            'can_act' => (bool) ($flags['can_act'] ?? false),
            'can_cancel' => (bool) ($flags['can_cancel'] ?? false),
            'can_manage' => (bool) ($flags['can_manage'] ?? false),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{field: string, label: string, current: mixed, proposed: mixed}>
     */
    public function changeRows(): array
    {
        $current = is_array($this->current_values) ? $this->current_values : [];
        $proposed = is_array($this->proposed_values) ? $this->proposed_values : [];
        $labels = [
            'address1' => 'Address 1',
            'address2' => 'Address 2',
            'localityId' => 'Locality',
            'phone' => 'Phone',
            'email' => 'Email',
        ];

        $rows = [];
        foreach (self::CHANGEABLE_FIELDS as $field) {
            if (! array_key_exists($field, $proposed)) {
                continue;
            }

            $value = $proposed[$field];
            $rows[] = [
                'field' => $field,
                'label' => $labels[$field] ?? $field,
                'current' => $current[$field] ?? null,
                'proposed' => $value,
                'currentLabel' => $field === 'localityId' ? ($current['localityName'] ?? $current[$field] ?? null) : ($current[$field] ?? null),
                'proposedLabel' => $field === 'localityId' ? ($proposed['localityName'] ?? $value) : $value,
            ];
        }

        return $rows;
    }
}
