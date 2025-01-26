<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalculationMode extends Model
{
    protected $table = 'calculation_mode';
    protected $primarykey = 'id';

    protected $fillable = [
       'name'
    ];
}
