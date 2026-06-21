<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractType extends Model
{
    protected $table = 'contract_type';
    protected $primaryKey = 'id';

    protected $fillable = [
        'name'
    ];

    public function employmentDetails(): HasMany {
        return $this->hasMany(EmploymentDetail::class);
    }
}
