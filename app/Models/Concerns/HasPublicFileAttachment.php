<?php

namespace App\Models\Concerns;

use App\Support\ConfiguredStorage;
use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasPublicFileAttachment
{
    protected function fileUrl(): Attribute
    {
        return Attribute::get(
            fn () => app(ConfiguredStorage::class)->urlOrNull($this->filePath),
        );
    }
}
