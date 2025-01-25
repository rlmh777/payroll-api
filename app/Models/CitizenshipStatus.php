<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CitizenshipStatus extends Model
{
    protected $table = 'citizenship_status';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];
}
