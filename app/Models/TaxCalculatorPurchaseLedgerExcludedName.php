<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TaxCalculatorPurchaseLedgerExcludedName extends Model
{
    use HasUuids;

    protected $table = 'tax_calculator_purchase_ledger_excluded_names';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
    ];
}
