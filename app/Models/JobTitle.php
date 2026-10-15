<?php

namespace App\Models;

use App\Support\ConfiguredStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobTitle extends Model
{
    protected $table = 'job_title';
    protected $primaryKey = 'id';

    protected $fillable = [
        'name',
        'payScale',
        'notes',
        'jobDescriptionPath',
    ];

    protected $appends = [
        'jobDescriptionUrl',
    ];

    public function employmentDetails(): HasMany
    {
        return $this->hasMany(EmploymentDetail::class, 'jobTitleId');
    }

    public function getJobDescriptionUrlAttribute(): ?string
    {
        if (!$this->jobDescriptionPath) {
            return null;
        }

        return app(ConfiguredStorage::class)->url($this->jobDescriptionPath);
    }
}
