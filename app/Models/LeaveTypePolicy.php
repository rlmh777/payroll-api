<?php

namespace App\Models;

use App\Enums\LeaveAccrualMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveTypePolicy extends Model
{
    protected $table = 'leave_type_policy';

    protected $fillable = [
        'leaveTypeId',
        'annualEntitlementDays',
        'accrualMethod',
        'isEnabled',
    ];

    protected $casts = [
        'annualEntitlementDays' => 'decimal:2',
        'isEnabled' => 'boolean',
    ];

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leaveTypeId');
    }

    public function accrualMethodEnum(): LeaveAccrualMethod
    {
        return LeaveAccrualMethod::fromStored($this->accrualMethod);
    }
}
