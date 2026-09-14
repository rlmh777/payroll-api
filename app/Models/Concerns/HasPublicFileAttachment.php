<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasPublicFileAttachment
{
    protected function fileUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->filePath ? asset('storage/'.$this->filePath) : null,
        );
    }
}
