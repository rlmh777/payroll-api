<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\HasMany;


class CalculationMode extends Model
{
    protected $table = 'calculation_mode';
    protected $primarykey = 'id';

    protected $fillable = [
       'name'
    ];

    public function payrolls(): HasMany {
        return $this->hasMany(Payroll::class);
    }

}
