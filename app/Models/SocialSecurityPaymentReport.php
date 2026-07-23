<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialSecurityPaymentReport extends Model
{
    use HasUuids;

    protected $table = 'social_security_payment_reports';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'year',
        'month',
        'calculated_at',
        'calculated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'calculated_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SocialSecurityPaymentReportLine::class, 'report_id')
            ->orderBy('sort_order')
            ->orderBy('calendar_week')
            ->orderBy('last_name')
            ->orderBy('first_name');
    }
}
