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
            'images' => [],
            'backgroundImageIds' => [],
            'blocks' => [
                [
                    'id' => 'heading-default',
                    'type' => 'heading',
                    'x' => 32,
                    'y' => 8,
                    'width' => 36,
                    'height' => 10,
                    'zIndex' => 2,
                    'text' => 'Sign in',
                    'fontSize' => 32,
                    'color' => '#ffffff',
                    'align' => 'center',
                    'imageId' => null,
                    'maxWidth' => 0,
                ],
                [
                    'id' => 'message-default',
                    'type' => 'message',
                    'x' => 32,
                    'y' => 18,
                    'width' => 36,
                    'height' => 8,
                    'zIndex' => 2,
                    'text' => 'Use your username or email to continue.',
                    'fontSize' => 14,
                    'color' => '#cbd5e1',
                    'align' => 'center',
                    'imageId' => null,
                    'maxWidth' => 0,
                ],
                [
                    'id' => 'form-default',
                    'type' => 'form',
                    'x' => 5,
                    'y' => 28,
                    'width' => 90,
                    'height' => 62,
                    'zIndex' => 3,
                    'text' => '',
                    'fontSize' => 16,
                    'color' => '#0f172a',
                    'align' => 'center',
                    'imageId' => null,
                    'maxWidth' => 400,
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
            if ($type === 'form') {
                $hasExplicitMax = array_key_exists('maxWidth', $block) && is_numeric($block['maxWidth']);
                $maxWidth = self::clamp((float) ($block['maxWidth'] ?? 400), 240, 960);
                $x = self::clamp((float) ($block['x'] ?? 10), 0, 95);
                $width = self::clamp((float) ($block['width'] ?? 30), 8, 100);
                if (! $hasExplicitMax && abs($x - 32.0) < 0.01 && abs($width - 36.0) < 0.01) {
                    $x = 5;
                    $width = 90;
                }
            } else {
                $x = self::clamp((float) ($block['x'] ?? 10), 0, 95);
                $width = self::clamp((float) ($block['width'] ?? 30), 8, 100);
            }

            $blocks[] = [
                'id' => is_string($block['id'] ?? null) && $block['id'] !== ''
                    ? $block['id']
                    : (string) Str::uuid(),
                'type' => $type,
                'x' => $x,
                'y' => self::clamp((float) ($block['y'] ?? 10), 0, 95),
                'width' => $width,
                'height' => self::clamp((float) ($block['height'] ?? 12), 6, 100),
                'zIndex' => max(1, (int) ($block['zIndex'] ?? 1)),
                'text' => mb_substr(trim((string) ($block['text'] ?? '')), 0, 500),
                'fontSize' => self::clamp((float) ($block['fontSize'] ?? 16), 10, 72),
                'color' => self::color((string) ($block['color'] ?? '#ffffff')),
                'align' => $align,
                'imageId' => $type === 'image' ? $imageId : null,
                'maxWidth' => $maxWidth,
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
            'images' => $images,
            'backgroundImageIds' => $backgroundImageIds,
            'blocks' => $blocks,
        ];
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
