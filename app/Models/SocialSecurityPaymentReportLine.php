<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialSecurityPaymentReportLine extends Model
{
    use HasUuids;

    protected $table = 'social_security_payment_report_lines';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'report_id',
        'employee_id',
        'payroll_id',
        'payroll_run_id',
        'employee_social_security_number',
        'company_social_security_number',
        'year',
        'month_name',
        'calendar_week',
        'week_monday_date',
        'weekly_gross_pay',
        'social_security_amount',
        'electronic_employer_number',
        'date_hired',
        'first_name',
        'last_name',
        'record_code',
        'sort_order',
    ];

    protected $casts = [
        'year' => 'integer',
        'calendar_week' => 'integer',
        'week_monday_date' => 'date:Y-m-d',
        'weekly_gross_pay' => 'decimal:2',
        'social_security_amount' => 'decimal:2',
        'date_hired' => 'date:Y-m-d',
        'sort_order' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(SocialSecurityPaymentReport::class, 'report_id');
    }
}
