<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmploymentStatus extends Model
{
    protected $table = 'employment_status';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

}
