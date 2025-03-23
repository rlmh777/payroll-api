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
        'name',
        'description',
        'code1',
        'code2',
        'parent_id',
        'type',
        'is_active',
        'balance',
        'level'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'balance' => 'decimal:2'
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
     * Get all descendants.
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get all ancestors.
     */
    public function ancestors(): HasMany
    {
        return $this->parent()->with('ancestors');
    }

    /**
     * Get the transactions for this account.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
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
