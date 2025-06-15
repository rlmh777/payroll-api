<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanType extends Model
{
    protected $table = 'loan_type';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'loanTypeId');
    }
}
