<?php

namespace App\Models\Concerns;

use App\Support\ConfiguredStorage;
use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasPublicFileAttachment
{
    public function getFileUrlAttribute(): ?string
    {
        return app(ConfiguredStorage::class)->urlOrNull($this->filePath);
    }

    protected function fileUrl(): Attribute
    {
        return Attribute::get(fn () => $this->getFileUrlAttribute());
    }
}
