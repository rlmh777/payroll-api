<?php

namespace App\Models;

use App\Enums\LeaveAccrualMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmploymentLeaveEntitlement extends Model
{
    use HasUuids;

    protected $table = 'employment_leave_entitlement';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employmentDetailId',
        'leaveTypeId',
        'annualEntitlementDays',
        'accrualMethod',
    ];

    protected $casts = [
        'annualEntitlementDays' => 'decimal:2',
    ];

    public function employmentDetail(): BelongsTo
    {
        return $this->belongsTo(EmploymentDetail::class, 'employmentDetailId');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leaveTypeId');
    }

    public function accrualMethodEnum(): ?LeaveAccrualMethod
    {
        if ($this->accrualMethod === null) {
            return null;
        }

        return LeaveAccrualMethod::fromStored($this->accrualMethod);
    }
}
