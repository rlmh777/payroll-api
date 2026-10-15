<?php

namespace App\Modules\Hr\Models;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\PipelineTemplate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormAssignment extends Model
{
    use HasUuids;

    public const AUDIENCE_COMPANY = 'company';

    public const AUDIENCE_DEPARTMENTS = 'departments';

    public const AUDIENCE_EMPLOYEE_GROUPS = 'employee_groups';

    public const AUDIENCE_EMPLOYEES = 'employees';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'form_template_id',
        'name',
        'audience_type',
        'show_on_login',
        'is_required',
        'is_active',
        'starts_at',
        'ends_at',
        'pipeline_template_id',
    ];

    protected $casts = [
        'show_on_login' => 'boolean',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * @return list<string>
     */
    public static function audienceTypes(): array
    {
        return [
            self::AUDIENCE_COMPANY,
            self::AUDIENCE_DEPARTMENTS,
            self::AUDIENCE_EMPLOYEE_GROUPS,
            self::AUDIENCE_EMPLOYEES,
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    public function pipelineTemplate(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplate::class, 'pipeline_template_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(
            Department::class,
            'form_assignment_department',
            'form_assignment_id',
            'department_id',
        )->withTimestamps();
    }

    public function employeeGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            EmployeeGroup::class,
            'form_assignment_employee_group',
            'form_assignment_id',
            'employee_group_id',
        )->withTimestamps();
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(
            Employee::class,
            'form_assignment_employee',
            'form_assignment_id',
            'employee_id',
        )->withTimestamps();
    }

    public function responses(): HasMany
    {
        return $this->hasMany(FormResponse::class, 'form_assignment_id');
    }

    public function isOpen(?\DateTimeInterface $at = null): bool
    {
        $now = $at ?? now();
        if ($this->starts_at && $this->starts_at->gt($now)) {
            return false;
        }
        if ($this->ends_at && $this->ends_at->lt($now)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(bool $includeTemplate = false): array
    {
        $payload = [
            'id' => $this->id,
            'form_template_id' => $this->form_template_id,
            'name' => $this->name ?: $this->template?->name,
            'audience_type' => $this->audience_type,
            'show_on_login' => $this->show_on_login,
            'is_required' => $this->is_required,
            'is_active' => $this->is_active,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'pipeline_template_id' => $this->pipeline_template_id,
            'pipeline_template' => $this->pipelineTemplate ? [
                'id' => $this->pipelineTemplate->id,
                'name' => $this->pipelineTemplate->name,
                'key' => $this->pipelineTemplate->key,
            ] : null,
            'department_ids' => $this->departments->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'departments' => $this->departments->map(fn (Department $department) => [
                'id' => (int) $department->id,
                'name' => $department->name,
            ])->values()->all(),
            'employee_group_ids' => $this->employeeGroups->pluck('id')->values()->all(),
            'employee_groups' => $this->employeeGroups->map(fn (EmployeeGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
            ])->values()->all(),
            'employee_ids' => $this->employees->pluck('id')->values()->all(),
            'employees' => $this->employees->map(function (Employee $employee) {
                $employee->loadMissing('person');

                return [
                    'id' => $employee->id,
                    'code' => $employee->code,
                    'name' => trim(($employee->firstName ?? '').' '.($employee->lastName ?? '')) ?: $employee->code,
                ];
            })->values()->all(),
            'response_count' => $this->relationLoaded('responses') ? $this->responses->count() : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($includeTemplate) {
            $payload['form_template'] = $this->template?->present();
        }

        return $payload;
    }
}
