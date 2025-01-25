<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gender extends Model
{
    protected $table = 'gender';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];


}
