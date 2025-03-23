<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ChartOfAccount extends Model
{
    use HasUuids;

    protected $table = 'chart_of_account';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'type',
        'parent_id',
        'description',
        'is_active',
        'balance',
        'level'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'balance' => 'decimal:2',
        'level' => 'integer'
    ];

    /**
     * Get the parent account.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_id');
    }

    /**
     * Get the child accounts.
     */
    public function children(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_id');
    }

    /**
     * Get all descendants recursively.
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get all ancestors recursively.
     */
    public function ancestors(): BelongsTo
    {
        return $this->parent()->with('ancestors');
    }

    public function deductions(): HasMany {
        return $this->hasMany(EmployeeDefaultDeduction::class);
    }

    public function employmentDetails(): HasMany {
        return $this->hasMany(EmploymentDetail::class);
    }

    public function historicalDeductions(): HasMany {
        return $this->hasMany(HistoricalEmployeeDeduction::class);
    }

    public function allowances(): HasMany {
        return $this->hasMany(EmployeeAllowance::class);
    }

    public function historicalAllowances(): HasMany {
        return $this->hasMany(HistoricalEmployeeAllowance::class);
    }

    public function loans(): HasMany {
        return $this->hasMany(Loan::class);
    }
}
