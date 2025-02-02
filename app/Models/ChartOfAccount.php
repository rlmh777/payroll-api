<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ChartOfAccount extends Model
{
    use HasUuids;

    protected $table = 'chart_of_account';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'description',
        'code1',
        'code2',
    ];

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
