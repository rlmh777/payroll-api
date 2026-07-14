<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeIncident extends Model
{
    use HasUuids;

    public const TYPES = [
        'MISCONDUCT',
        'ATTENDANCE',
        'SAFETY',
        'PERFORMANCE',
        'HARASSMENT',
        'ACCIDENT',
        'POLICY_VIOLATION',
        'OTHER',
    ];

    public const SEVERITIES = [
        'LOW',
        'MEDIUM',
        'HIGH',
        'CRITICAL',
    ];

    public const STATUSES = [
        'DRAFT',
        'REPORTED',
        'UNDER_REVIEW',
        'ACTION_TAKEN',
        'CLOSED',
        'DISMISSED',
    ];

    public const ACTIONS = [
        'NONE',
        'VERBAL_WARNING',
        'WRITTEN_WARNING',
        'SUSPENSION',
        'TRAINING',
        'TERMINATION_REFERRAL',
        'OTHER',
    ];

    protected $table = 'employee_incident';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'incidentDate',
        'reportedDate',
        'incidentType',
        'severity',
        'title',
        'description',
        'status',
        'reportedByEmployeeId',
        'departmentId',
        'worksiteId',
        'actionTaken',
        'actionDate',
        'followUpDate',
        'resolutionNotes',
        'employeeAcknowledged',
        'acknowledgedAt',
        'notes',
    ];

    protected $casts = [
        'incidentDate' => 'date:Y-m-d',
        'reportedDate' => 'date:Y-m-d',
        'actionDate' => 'date:Y-m-d',
        'followUpDate' => 'date:Y-m-d',
        'employeeAcknowledged' => 'boolean',
        'acknowledgedAt' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reportedByEmployeeId');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'departmentId');
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class, 'worksiteId');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmployeeIncidentAttachment::class, 'employeeIncidentId');
    }
}
