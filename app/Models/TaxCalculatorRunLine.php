<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxCalculatorRunLine extends Model
{
    use HasUuids;

    protected $table = 'tax_calculator_run_lines';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tax_calculator_run_id',
        'account_id',
        'account_code',
        'account_name',
        'amount',
        'business_tax_code',
        'gst_code',
        'include_btb',
        'is_rollup',
        'row_type',
        'group_index',
        'include_in_tax',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'float',
        'include_btb' => 'boolean',
        'is_rollup' => 'boolean',
        'group_index' => 'integer',
        'include_in_tax' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(TaxCalculatorRun::class, 'tax_calculator_run_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
