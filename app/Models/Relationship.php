<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Relationship extends Model
{
    protected $table = 'relationship';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];
}
