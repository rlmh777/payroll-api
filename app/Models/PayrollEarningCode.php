<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollEarningCode extends Model
{
    public const SOURCE_TIMESHEET_REGULAR = 'timesheet_regular';

    public const SOURCE_TIMESHEET_OVERTIME = 'timesheet_overtime';

    public const SOURCE_TIMESHEET_HOLIDAY = 'timesheet_holiday';

    public const SOURCE_VACATION = 'vacation';

    public const SOURCE_ALLOWANCE_FALLBACK = 'allowance_fallback';

    public const SOURCES = [
        self::SOURCE_TIMESHEET_REGULAR,
        self::SOURCE_TIMESHEET_OVERTIME,
        self::SOURCE_TIMESHEET_HOLIDAY,
        self::SOURCE_VACATION,
        self::SOURCE_ALLOWANCE_FALLBACK,
    ];

    protected $table = 'payroll_earning_code';

    protected $fillable = [
        'code',
        'name',
        'account_id',
        'source',
        'post_to_department_account',
        'is_taxable',
        'is_ss_subject',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_taxable' => 'boolean',
        'is_ss_subject' => 'boolean',
        'post_to_department_account' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            self::SOURCE_TIMESHEET_REGULAR => 'Timesheet regular',
            self::SOURCE_TIMESHEET_OVERTIME => 'Timesheet overtime',
            self::SOURCE_TIMESHEET_HOLIDAY => 'Timesheet holiday',
            self::SOURCE_VACATION => 'Vacation pay',
            self::SOURCE_ALLOWANCE_FALLBACK => 'Other payments fallback',
        ];
    }

    public function usesDepartmentAccount(): bool
    {
        return (bool) $this->post_to_department_account;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function earningLines(): HasMany
    {
        return $this->hasMany(PayrollEarningLine::class, 'payroll_earning_code_id');
    }
}
