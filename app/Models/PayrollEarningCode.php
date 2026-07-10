<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollEarningCode extends Model
{
    protected $table = 'payroll_earning_code';

    protected $fillable = [
        'code',
        'name',
        'account_id',
        'is_taxable',
        'is_ss_subject',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_taxable' => 'boolean',
        'is_ss_subject' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function earningLines(): HasMany
    {
        return $this->hasMany(PayrollEarningLine::class, 'payroll_earning_code_id');
    }
}
