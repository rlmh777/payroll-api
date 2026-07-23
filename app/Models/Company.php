<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    protected $table = 'company';

    protected $primaryKey = 'id';

    protected $fillable = [
        'legalName',
        'alias',
        'socialSecurityNumber',
        'socialSecurityElectronicEmployerNumber',
        'bankBranchNumber',
        'taxIdentificationNumber',
        'logoPath',
        'phoneNumber1',
        'phoneNumber2',
        'email',
        'street',
        'localityId',
        'primaryColor',
        'secondaryColor',
    ];

    protected $attributes = [
        'primaryColor' => '#1976D2',
        'secondaryColor' => '#26A69A',
    ];

    protected $appends = [
        'logoUrl',
    ];

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class, 'localityId');
    }

    public function companyBankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class, 'companyId');
    }

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logoPath) {
            return null;
        }

        return url(Storage::disk('public')->url($this->logoPath));
    }

    protected function socialSecurityNumber(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (is_resource($value)) {
                    return stream_get_contents($value) ?: '';
                }

                return $value ?? '';
            },
        );
    }

    public function toArray(): array
    {
        $array = parent::toArray();

        foreach ($array as $key => $value) {
            if (is_resource($value)) {
                $array[$key] = stream_get_contents($value) ?: '';
            }
        }

        return $array;
    }
}
