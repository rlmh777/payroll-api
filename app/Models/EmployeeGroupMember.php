<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeGroupMember extends Model
{
    use HasUuids;

    protected $table = 'employee_group_member';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'employeeGroupId',
        'employeeId',
        'startDate',
        'endDate',
    ];

    protected $casts = [
        'startDate' => 'date:Y-m-d',
        'endDate' => 'date:Y-m-d',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(EmployeeGroup::class, 'employeeGroupId');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function scopeActive(Builder $query, ?string $asOfDate = null): Builder
    {
        $date = $asOfDate ?? now()->format('Y-m-d');

        return $query
            ->where(function (Builder $inner) use ($date) {
                $inner->whereNull('startDate')
                    ->orWhereDate('startDate', '<=', $date);
            })
            ->where(function (Builder $inner) use ($date) {
                $inner->whereNull('endDate')
                    ->orWhereDate('endDate', '>=', $date);
            });
    }
}
