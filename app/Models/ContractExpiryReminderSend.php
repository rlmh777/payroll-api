<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractExpiryReminderSend extends Model
{
    use HasUuids;

    protected $table = 'contract_expiry_reminder_sends';

    protected $fillable = [
        'employment_detail_id',
        'offset_value',
        'offset_unit',
        'sent_on',
    ];

    protected $casts = [
        'sent_on' => 'date:Y-m-d',
        'offset_value' => 'integer',
    ];

    public function employmentDetail(): BelongsTo
    {
        return $this->belongsTo(EmploymentDetail::class, 'employment_detail_id');
    }
}
