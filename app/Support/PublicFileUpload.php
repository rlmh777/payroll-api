<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class PublicFileUpload
{
    /** @var list<string> */
    public const RULES = [
        'file',
        'mimes:pdf,doc,docx,jpg,jpeg,png,gif,webp,xls,xlsx,txt',
        'max:20480',
    ];

    /**
     * @return list<string>
     */
    public static function optionalRules(): array
    {
        return array_merge(['nullable'], self::RULES);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function apply(
        Request $request,
        array $data,
        string $directory,
        string $inputName = 'attachmentFile',
        ?string $existingPath = null,
        string $filenamePrefix = 'file_',
    ): array {
        unset($data[$inputName]);

        if (! $request->hasFile($inputName)) {
            return $data;
        }

        /** @var UploadedFile $file */
        $file = $request->file($inputName);
        self::delete($existingPath);

        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $storedName = $filenamePrefix.Str::uuid().'.'.$extension;
        $filePath = app(ConfiguredStorage::class)->store($file, $directory, $storedName);

        $data['filePath'] = $filePath;
        $data['fileName'] = $file->getClientOriginalName();
        $data['mimeType'] = $file->getClientMimeType();
        $data['fileSize'] = $file->getSize();

        return $data;
    }

    public static function delete(?string $path): void
    {
        app(ConfiguredStorage::class)->delete($path);
    }
}
