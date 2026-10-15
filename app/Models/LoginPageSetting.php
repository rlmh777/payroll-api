<?php

namespace App\Models;

use App\Support\ConfiguredStorage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LoginPageSetting extends Model
{
    use HasUuids;

    public const BLOCK_TYPES = ['form', 'heading', 'message', 'image'];

    public const BACKGROUND_MODES = ['color', 'image', 'carousel'];

    protected $table = 'login_page_settings';

    protected $fillable = [
        'layout',
    ];

    protected $casts = [
        'layout' => 'array',
    ];

    public static function current(): self
    {
        if (! Schema::hasTable('login_page_settings')) {
            $settings = new self;
            $settings->layout = self::defaultLayout();

            return $settings;
        }

        $settings = static::query()->first();
        if ($settings) {
            return $settings;
        }

        return static::query()->create([
            'layout' => self::defaultLayout(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultLayout(): array
    {
        return [
            'backgroundMode' => 'color',
            'backgroundColor' => '#0f172a',
            'carouselIntervalMs' => 7000,
            'gridColumns' => 24,
            'gridRows' => 24,
            'images' => [],
            'backgroundImageIds' => [],
            'blocks' => [
                [
                    'id' => 'heading-default',
                    'type' => 'heading',
                    'x' => 25.0,
                    'y' => 0.0,
                    'width' => 50.0,
                    'height' => 12.5,
                    'col' => 6,
                    'row' => 0,
                    'colSpan' => 12,
                    'rowSpan' => 3,
                    'zIndex' => 2,
                    'text' => 'Sign in',
                    'fontSize' => 40,
                    'color' => '#ffffff',
                    'align' => 'center',
                    'imageId' => null,
                    'maxWidth' => 0,
                    'headingLevel' => 1,
                    'cardBackground' => '#ffffff',
                    'cardBorderColor' => '#e2e8f0',
                    'cardBorderWidth' => 0,
                    'cardRadius' => 12,
                    'cardShadow' => 2,
                ],
                [
                    'id' => 'form-default',
                    'type' => 'form',
                    'x' => 25.0,
                    'y' => 25.0,
                    'width' => 50.0,
                    'height' => 62.5,
                    'col' => 6,
                    'row' => 6,
                    'colSpan' => 12,
                    'rowSpan' => 15,
                    'zIndex' => 3,
                    'text' => '',
                    'fontSize' => 16,
                    'color' => '#0f172a',
                    'align' => 'center',
                    'imageId' => null,
                    'maxWidth' => 400,
                    'headingLevel' => 1,
                    'cardBackground' => '#ffffff',
                    'cardBorderColor' => '#e2e8f0',
                    'cardBorderWidth' => 0,
                    'cardRadius' => 12,
                    'cardShadow' => 2,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    public static function normalizeLayout(array $layout): array
    {
        $defaults = self::defaultLayout();
        $mode = strtolower((string) ($layout['backgroundMode'] ?? $defaults['backgroundMode']));
        if (! in_array($mode, self::BACKGROUND_MODES, true)) {
            $mode = 'color';
        }

        $images = [];
        foreach ($layout['images'] ?? [] as $image) {
            if (! is_array($image) || ! filled($image['path'] ?? null)) {
                continue;
            }
            $id = is_string($image['id'] ?? null) && $image['id'] !== ''
                ? $image['id']
                : (string) Str::uuid();
            $images[] = [
                'id' => $id,
                'path' => (string) $image['path'],
            ];
        }

        $imageIds = array_map(fn (array $image) => $image['id'], $images);
        $backgroundImageIds = [];
        foreach ($layout['backgroundImageIds'] ?? [] as $id) {
            if (is_string($id) && in_array($id, $imageIds, true) && ! in_array($id, $backgroundImageIds, true)) {
                $backgroundImageIds[] = $id;
            }
        }

        $gridColumns = (int) self::clamp((float) ($layout['gridColumns'] ?? $defaults['gridColumns']), 2, 24);
        $gridRows = (int) self::clamp((float) ($layout['gridRows'] ?? $defaults['gridRows']), 2, 24);

        $blocks = [];
        $hasForm = false;
        foreach ($layout['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = strtolower((string) ($block['type'] ?? ''));
            if (! in_array($type, self::BLOCK_TYPES, true)) {
                continue;
            }
            if ($type === 'form') {
                if ($hasForm) {
                    continue;
                }
                $hasForm = true;
            }

            $imageId = is_string($block['imageId'] ?? null) ? $block['imageId'] : null;
            if ($imageId && ! in_array($imageId, $imageIds, true)) {
                $imageId = null;
            }

            $align = strtolower((string) ($block['align'] ?? 'center'));
            if (! in_array($align, ['left', 'center', 'right'], true)) {
                $align = 'center';
            }

            $maxWidth = 0;
            $x = self::clamp((float) ($block['x'] ?? 10), 0, 99);
            $width = self::clamp((float) ($block['width'] ?? 30), 1, 100);
            $y = self::clamp((float) ($block['y'] ?? 10), 0, 99);
            $height = self::clamp((float) ($block['height'] ?? 12), 1, 100);
            if ($type === 'form') {
                $hasExplicitMax = array_key_exists('maxWidth', $block) && is_numeric($block['maxWidth']);
                $maxWidth = self::clamp((float) ($block['maxWidth'] ?? 400), 240, 960);
                if (! $hasExplicitMax && abs($x - 32.0) < 0.01 && abs($width - 36.0) < 0.01) {
                    $x = 5;
                    $width = 90;
                }
            }

            $hasGrid = array_key_exists('col', $block)
                || array_key_exists('row', $block)
                || array_key_exists('colSpan', $block)
                || array_key_exists('rowSpan', $block);
            $placement = $hasGrid
                ? self::placementFromGrid(
                    (int) ($block['col'] ?? 0),
                    (int) ($block['row'] ?? 0),
                    (int) ($block['colSpan'] ?? 1),
                    (int) ($block['rowSpan'] ?? 1),
                    $gridColumns,
                    $gridRows,
                )
                : self::snapRectToGrid($x, $y, $width, $height, $gridColumns, $gridRows);

            $blocks[] = [
                'id' => is_string($block['id'] ?? null) && $block['id'] !== ''
                    ? $block['id']
                    : (string) Str::uuid(),
                'type' => $type,
                'x' => $placement['x'],
                'y' => $placement['y'],
                'width' => $placement['width'],
                'height' => $placement['height'],
                'col' => $placement['col'],
                'row' => $placement['row'],
                'colSpan' => $placement['colSpan'],
                'rowSpan' => $placement['rowSpan'],
                'zIndex' => max(1, (int) ($block['zIndex'] ?? 1)),
                'text' => mb_substr(trim((string) ($block['text'] ?? '')), 0, 500),
                'fontSize' => self::clamp((float) ($block['fontSize'] ?? 16), 10, 72),
                'color' => self::color((string) ($block['color'] ?? '#ffffff')),
                'align' => $align,
                'imageId' => $type === 'image' ? $imageId : null,
                'maxWidth' => $maxWidth,
                'headingLevel' => $type === 'heading'
                    ? (int) self::clamp((float) ($block['headingLevel'] ?? 1), 1, 6)
                    : 1,
                'cardBackground' => self::color((string) ($block['cardBackground'] ?? '#ffffff')),
                'cardBorderColor' => self::color((string) ($block['cardBorderColor'] ?? '#e2e8f0')),
                'cardBorderWidth' => (int) self::clamp((float) ($block['cardBorderWidth'] ?? 0), 0, 12),
                'cardRadius' => (int) self::clamp((float) ($block['cardRadius'] ?? 12), 0, 48),
                'cardShadow' => (int) self::clamp((float) ($block['cardShadow'] ?? 2), 0, 3),
            ];
        }

        if (! $hasForm) {
            foreach ($defaults['blocks'] as $block) {
                if ($block['type'] === 'form') {
                    $blocks[] = $block;
                    break;
                }
            }
        }

        $interval = (int) ($layout['carouselIntervalMs'] ?? $defaults['carouselIntervalMs']);

        return [
            'backgroundMode' => $mode,
            'backgroundColor' => self::color((string) ($layout['backgroundColor'] ?? $defaults['backgroundColor'])),
            'carouselIntervalMs' => self::clamp($interval, 2000, 30000),
            'gridColumns' => $gridColumns,
            'gridRows' => $gridRows,
            'images' => $images,
            'backgroundImageIds' => $backgroundImageIds,
            'blocks' => $blocks,
        ];
    }

    /**
     * @return array{col: int, row: int, colSpan: int, rowSpan: int, x: float, y: float, width: float, height: float}
     */
    private static function placementFromGrid(
        int $col,
        int $row,
        int $colSpan,
        int $rowSpan,
        int $columns,
        int $rows,
    ): array {
        $colSpan = (int) self::clamp($colSpan, 1, $columns);
        $rowSpan = (int) self::clamp($rowSpan, 1, $rows);
        $col = (int) self::clamp($col, 0, $columns - $colSpan);
        $row = (int) self::clamp($row, 0, $rows - $rowSpan);
        $colSpan = (int) self::clamp($colSpan, 1, $columns - $col);
        $rowSpan = (int) self::clamp($rowSpan, 1, $rows - $row);

        return [
            'col' => $col,
            'row' => $row,
            'colSpan' => $colSpan,
            'rowSpan' => $rowSpan,
            'x' => $col / $columns * 100,
            'y' => $row / $rows * 100,
            'width' => $colSpan / $columns * 100,
            'height' => $rowSpan / $rows * 100,
        ];
    }

    /**
     * @return array{col: int, row: int, colSpan: int, rowSpan: int, x: float, y: float, width: float, height: float}
     */
    private static function snapRectToGrid(
        float $x,
        float $y,
        float $width,
        float $height,
        int $columns,
        int $rows,
    ): array {
        $colSpan = (int) self::clamp((int) round($width / 100 * $columns) ?: 1, 1, $columns);
        $rowSpan = (int) self::clamp((int) round($height / 100 * $rows) ?: 1, 1, $rows);
        $col = (int) self::clamp((int) round($x / 100 * $columns), 0, $columns - $colSpan);
        $row = (int) self::clamp((int) round($y / 100 * $rows), 0, $rows - $rowSpan);

        return self::placementFromGrid($col, $row, $colSpan, $rowSpan, $columns, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $layout = self::normalizeLayout(is_array($this->layout) ? $this->layout : self::defaultLayout());
        $storage = app(ConfiguredStorage::class);

        $images = [];
        foreach ($layout['images'] as $image) {
            $images[] = [
                'id' => $image['id'],
                'path' => $image['path'],
                'url' => $storage->urlOrNull($image['path']),
            ];
        }
        $layout['images'] = $images;

        return $layout;
    }

    public function addImage(string $path): array
    {
        $layout = self::normalizeLayout(is_array($this->layout) ? $this->layout : self::defaultLayout());
        $image = [
            'id' => (string) Str::uuid(),
            'path' => $path,
        ];
        $layout['images'][] = $image;
        $this->layout = $layout;
        $this->save();

        return $image;
    }

    public function removeImage(string $imageId): void
    {
        $layout = self::normalizeLayout(is_array($this->layout) ? $this->layout : self::defaultLayout());
        $removed = null;
        $layout['images'] = array_values(array_filter(
            $layout['images'],
            function (array $image) use ($imageId, &$removed) {
                if ($image['id'] === $imageId) {
                    $removed = $image;

                    return false;
                }

                return true;
            },
        ));
        $layout['backgroundImageIds'] = array_values(array_filter(
            $layout['backgroundImageIds'],
            fn (string $id) => $id !== $imageId,
        ));
        $layout['blocks'] = array_map(function (array $block) use ($imageId) {
            if (($block['imageId'] ?? null) === $imageId) {
                $block['imageId'] = null;
            }

            return $block;
        }, $layout['blocks']);

        $this->layout = $layout;
        $this->save();

        if (is_array($removed) && filled($removed['path'] ?? null)) {
            app(ConfiguredStorage::class)->delete($removed['path']);
        }
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private static function color(string $value): string
    {
        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1
            ? $value
            : '#0f172a';
    }
}
