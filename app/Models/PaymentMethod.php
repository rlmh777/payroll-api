<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    protected $table = 'payment_method';

    protected $primaryKey = 'id';

    protected $fillable = [
        'name',
        'requiresBankAccount',
    ];

    protected $casts = [
        'requiresBankAccount' => 'boolean',
    ];

    protected $attributes = [
        'requiresBankAccount' => true,
    ];

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class, 'paymentMethodId');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(\App\Modules\Hr\Models\Employee::class, 'paymentMethodId');
    }
}
