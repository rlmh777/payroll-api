<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmploymentDetail extends Model
{
    use HasUuids;

    protected $table = 'employment_detail';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'startDate',
        'endDate',
        'isActive',
        'jobTitle',
        'requiresClocking',
        'benefits',
        'accountId',
        'contractTypeId',
        'employmentPolicies',
        'contractAgreementPath',
        'departmentId',
        'worksiteId',
        'defaultPayPeriodGroupId',
    ];

    protected $casts = [
        'startDate' => 'date:Y-m-d',
        'endDate' => 'date:Y-m-d',
        'isActive' => 'boolean',
        'requiresClocking' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accountId');
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class, 'contractTypeId');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'departmentId');
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class, 'worksiteId');
    }

    public function defaultPayPeriodGroup(): BelongsTo
    {
        return $this->belongsTo(PayPeriodGroup::class, 'defaultPayPeriodGroupId');
    }

    public function leaveEntitlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmploymentLeaveEntitlement::class, 'employmentDetailId');
    }
}
