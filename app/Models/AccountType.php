<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountType extends Model
{
    protected $table = 'account_types';

    protected $fillable = [
        'name',
    ];

    /**
     * Get all chart of accounts with this account type
     */
    public function chartOfAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'category_id');
    }
}

