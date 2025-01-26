<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Degree extends Model
{
    protected $table = 'degree';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];
}

