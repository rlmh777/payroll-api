<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccountType extends Model
{
    protected $table = 'bank_account_type';
    protected $primarykey = 'id';

    protected $fillable = [
       'name'
    ];

    public function companyBankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class, 'accountTypeId');
    }
}
