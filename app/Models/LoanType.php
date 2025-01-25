<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanType extends Model
{
    protected $table = 'loan_Type';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];
}
