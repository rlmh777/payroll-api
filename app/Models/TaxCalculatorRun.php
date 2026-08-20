<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxCalculatorRun extends Model
{
    use HasUuids;

    protected $table = 'tax_calculator_runs';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $hidden = [
        'import_accounts_sheet',
        'import_gst_sheet',
        'import_purchase_ledger_sheet',
    ];

    protected $fillable = [
        'year',
        'month',
        'gst_value_entered',
        'partial_exemptions_total',
        'line_220',
        'net_of_2251',
        'import_filename',
        'imported_at',
        'import_accounts_sheet',
        'import_gst_sheet',
        'import_purchase_ledger_sheet',
        'import_purchase_ledger_filename',
        'import_purchase_ledger_at',
        'rates_snapshot',
        'results',
        'created_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'gst_value_entered' => 'float',
        'partial_exemptions_total' => 'float',
        'line_220' => 'float',
        'net_of_2251' => 'float',
        'imported_at' => 'datetime',
        'import_purchase_ledger_at' => 'datetime',
        'import_accounts_sheet' => 'array',
        'import_gst_sheet' => 'array',
        'import_purchase_ledger_sheet' => 'array',
        'rates_snapshot' => 'array',
        'results' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(TaxCalculatorRunLine::class, 'tax_calculator_run_id')
            ->orderBy('sort_order')
            ->orderBy('account_name');
    }
}
