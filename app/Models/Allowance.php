<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Allowance extends Model
{
    use HasUuids;

    protected $table = 'allowance';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'isTaxable',
        'isSocialSecurityDeductable',
        'note',
        'defaultAmount'
    ];

    public function employeeDefaultAllowance(): HasMany
    {
        return $this->hasMany(EmployeeDefaultAllowance::class);
    }

    public function employeeHistoricalAllowance(): HasMany
    {
        return $this->hasMany(HistoricalEmployeeAllowance::class);
    }

}
