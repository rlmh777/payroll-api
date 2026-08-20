<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TaxCalculatorRate extends Model
{
    use HasUuids;

    protected $table = 'tax_calculator_rates';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'category',
        'code',
        'name',
        'rate',
        'iris_line',
        'applies_to_accounts',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'rate' => 'float',
        'applies_to_accounts' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
