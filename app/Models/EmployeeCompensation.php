<?php

namespace App\Models;

use App\Enums\CompensationMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCompensation extends Model
{
    use HasUuids;

    protected $table = 'employee_compensation';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'employmentDetailId',
        'effectiveDate',
        'endDate',
        'isActive',
        'compensationMethod',
        'requiresClocking',
        'hourlyRate',
        'yearlyRate',
        'standardWeeklyHours',
        'payscale',
        'payscalePoint',
        'reasonType',
        'reasonNote',
        'approvedById',
    ];

    protected $casts = [
        'effectiveDate' => 'date:Y-m-d',
        'endDate' => 'date:Y-m-d',
        'isActive' => 'boolean',
        'requiresClocking' => 'boolean',
        'hourlyRate' => 'decimal:2',
        'yearlyRate' => 'decimal:2',
        'standardWeeklyHours' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function employmentDetail(): BelongsTo
    {
        return $this->belongsTo(EmploymentDetail::class, 'employmentDetailId');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approvedById');
    }

    public function isHourlyCompensation(): bool
    {
        return CompensationMethod::fromStored($this->compensationMethod)->isHourlyBased();
    }

    public function isBaseSalaryCompensation(): bool
    {
        return CompensationMethod::fromStored($this->compensationMethod)->isBaseBased();
    }

    public function compensationMethodEnum(): CompensationMethod
    {
        return CompensationMethod::fromStored($this->compensationMethod);
    }
}
