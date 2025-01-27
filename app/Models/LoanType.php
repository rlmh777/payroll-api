<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\HasMany;

class LoanType extends Model
{
    protected $table = 'loan_Type';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function loans(): HasMany {
        return $this->hasMany(Loan::class);
    }
}
