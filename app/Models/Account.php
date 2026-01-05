<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Account extends Model
{
    use HasUuids;

    protected $table = 'accounts';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'description',
        'code1',
        'code2',
        'balance',
        'parent_id',
        'account_type_id'
    ];

    public function deductions(): HasMany {
        return $this->hasMany(EmployeeDefaultDeduction::class, 'accountId');
    }

    public function employmentDetails(): HasMany {
        return $this->hasMany(EmploymentDetail::class, 'accountId');
    }

    public function historicalDeductions(): HasMany {
        return $this->hasMany(HistoricalEmployeeDeduction::class, 'accountId');
    }

    public function allowances(): HasMany {
        return $this->hasMany(EmployeeDefaultAllowance::class, 'accountId');
    }

    public function historicalAllowances(): HasMany {
        return $this->hasMany(HistoricalEmployeeAllowance::class, 'accountId');
    }

    public function loans(): HasMany {
        return $this->hasMany(Loan::class, 'accountId');
    }

    public function accountType(): BelongsTo {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }

    public function parent(): BelongsTo {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany {
        return $this->hasMany(Account::class, 'parent_id');
    }

    /**
     * Get all descendants recursively
     */
    public function descendants(): \Illuminate\Support\Collection
    {
        $descendants = collect();
        
        foreach ($this->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->descendants());
        }
        
        return $descendants;
    }

    /**
     * Get all ancestors (parent chain)
     */
    public function ancestors(): \Illuminate\Support\Collection
    {
        $ancestors = collect();
        $parent = $this->parent;
        
        while ($parent) {
            $ancestors->push($parent);
            $parent = $parent->parent;
        }
        
        return $ancestors->reverse();
    }

    /**
     * Check if this account is a descendant of the given account
     */
    public function isDescendantOf(Account $account): bool
    {
        return $this->ancestors()->contains('id', $account->id);
    }

    /**
     * Check if this account is a root account (has no parent)
     */
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Scope to get only root accounts
     */
    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope to get only sub-accounts (has a parent)
     */
    public function scopeSubAccounts($query)
    {
        return $query->whereNotNull('parent_id');
    }

    /**
     * Scope to get children of a specific parent
     */
    public function scopeChildrenOf($query, $parentId)
    {
        return $query->where('parent_id', $parentId);
    }

    /**
     * Get the depth level in the hierarchy (0 for root)
     */
    public function getDepthAttribute(): int
    {
        return $this->ancestors()->count();
    }

}

