<?php

namespace App\Modules\Payroll\Services;

class ReportDocumentHeaderDefaults
{
    /**
     * @return list<array{name: string, kind: string, is_system: bool, canvas: array<string, mixed>}>
     */
    public static function all(): array
    {
        return [
            [
                'name' => 'Company',
                'kind' => 'company',
                'is_system' => true,
                'canvas' => self::companyCanvas(),
            ],
            [
                'name' => 'GOB',
                'kind' => 'government',
                'is_system' => true,
                'canvas' => self::governmentCanvas(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function companyCanvas(): array
    {
        return [
            'width' => 980,
            'height' => 120,
            'background' => '#ffffff',
            'elements' => [
                self::image('company-logo', 16, 16, 84, 84, 'company.logo'),
                self::text('company-name', 116, 18, 820, 30, '{{company.name}}', 20, 'bold', 'left', '#1a1a1a'),
                self::text('company-address', 116, 52, 820, 22, '{{company.address}}', 13, 'normal', 'left', '#333333'),
                self::text('company-contact', 116, 78, 820, 20, '{{company.email}}  {{company.phone}}', 12, 'normal', 'left', '#555555'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function governmentCanvas(): array
    {
        return [
            'width' => 980,
            'height' => 132,
            'background' => '#f4f7fb',
            'elements' => [
                self::text('gob-title', 20, 14, 940, 30, 'GOVERNMENT OF BELIZE', 18, 'bold', 'center', '#1b4f72'),
                self::text('gob-company', 20, 50, 940, 24, '{{company.name}}', 14, 'bold', 'center', '#1a1a1a'),
                self::text('gob-address', 20, 76, 940, 20, '{{company.address}}', 12, 'normal', 'center', '#333333'),
                self::text('gob-tin', 20, 98, 940, 20, 'TIN {{company.tin}}', 12, 'normal', 'center', '#333333'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(
        string $id,
        int $x,
        int $y,
        int $width,
        int $height,
        string $text,
        int $fontSize,
        string $fontWeight,
        string $align,
        string $color,
    ): array {
        return [
            'id' => $id,
            'type' => 'text',
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'text' => $text,
            'fontSize' => $fontSize,
            'fontWeight' => $fontWeight,
            'align' => $align,
            'color' => $color,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function image(string $id, int $x, int $y, int $width, int $height, string $field): array
    {
        return [
            'id' => $id,
            'type' => 'image',
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'field' => $field,
            'text' => '',
            'fontSize' => 14,
            'fontWeight' => 'normal',
            'align' => 'left',
            'color' => '#000000',
        ];
    }
}
