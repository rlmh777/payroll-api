<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Bank extends Model
{
    use HasUuids;

    protected $table = 'bank';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'code'
     
    ];

    public function employeeBank(): HasMany {
        return $this->hasMany(EmployeeBank::class);
    }

    public function vendors(): HasMany {
        return $this->hasMany(Vendor::class);
    }

    public function employeeDefaultDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDefaultDeduction::class, 'bankId');
    }

    public function companyBankAccounts(): HasMany {
        return $this->hasMany(CompanyBankAccount::class, 'bankId');
    }

}
