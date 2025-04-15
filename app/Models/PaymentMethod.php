<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\HasMany;

class PaymentMethod extends Model
{
    protected $table = 'payment_method';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function payrolls(): HasMany {
        return $this->hasMany(Payrolls::class);
    }

    public function employees(): HasMany {
        return $this->hasMany(Employees::class);
    }
}
