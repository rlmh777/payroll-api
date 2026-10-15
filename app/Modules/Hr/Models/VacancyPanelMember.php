<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VacancyPanelMember extends Model
{
    use HasUuids;

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'vacancy_id',
        'name',
        'email',
        'user_id',
        'invite_code_hash',
        'invite_code_expires_at',
        'invited_at',
        'last_login_at',
        'status',
    ];

    protected $casts = [
        'invite_code_expires_at' => 'datetime',
        'invited_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class, 'vacancy_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(FormResponse::class, 'panel_member_id');
    }

    public function isUsable(): bool
    {
        return $this->status !== self::STATUS_REVOKED;
    }

    public function codeIsExpired(): bool
    {
        return $this->invite_code_expires_at !== null
            && $this->invite_code_expires_at->isPast();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'vacancy_id' => $this->vacancy_id,
            'name' => $this->name,
            'email' => $this->email,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'invited_at' => $this->invited_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'invite_expires_at' => $this->invite_code_expires_at?->toIso8601String(),
        ];
    }
}
