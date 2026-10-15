<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxCalculatorAccountRate extends Model
{
    use HasUuids;

    protected $table = 'tax_calculator_account_rates';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tax_calculator_account_id',
        'rate_code',
        'tax_basis',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(TaxCalculatorAccount::class, 'tax_calculator_account_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(TaxCalculatorRate::class, 'rate_code', 'code');
    }
}
