<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CompanyBankAccount extends Model
{
    use HasUuids;

    protected $table = 'company_bank_accounts';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'companyId',
        'bankId',
        'accountNumber',
        'accountTypeId'
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'companyId');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bankId');
    }

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(BankAccountType::class, 'accountTypeId');
    }
} 