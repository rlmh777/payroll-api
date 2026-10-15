<?php

namespace App\Modules\Core\Services;

use InvalidArgumentException;
use RuntimeException;

class ApplicationLogService
{
    public const DEFAULT_LINES = 500;

    public const MAX_LINES = 5000;

    public const MAX_BYTES = 5_000_000;

    /**
     * @return list<string>
     */
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public function directory(): string
    {
        return storage_path('logs');
    }

    /**
     * @return list<array{name: string, sizeBytes: int, modifiedAt: string|null}>
     */
    public function files(): array
    {
        $directory = $this->directory();
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.log') ?: [] as $path) {
            $name = basename($path);
            if (! $this->isAllowedName($name)) {
                continue;
            }

            $modified = @filemtime($path) ?: null;
            $files[] = [
                'name' => $name,
                'sizeBytes' => (int) (@filesize($path) ?: 0),
                'modifiedAt' => $modified ? date('c', $modified) : null,
            ];
        }

        usort($files, static function (array $left, array $right): int {
            return strcmp((string) ($right['modifiedAt'] ?? ''), (string) ($left['modifiedAt'] ?? ''));
        });

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(?string $file, int $lines = self::DEFAULT_LINES, ?string $search = null, ?string $level = null): array
    {
        $files = $this->files();
        $selected = $this->resolveSelectedFile($file, $files);
        $lines = max(50, min(self::MAX_LINES, $lines));
        $level = $level !== null ? strtolower(trim($level)) : null;
        if ($level === '') {
            $level = null;
        }

        $channel = (string) config('logging.default', 'stack');
        $payload = [
            'channel' => $channel,
            'stack' => config("logging.channels.{$channel}.channels"),
            'directory' => $this->directory(),
            'files' => $files,
            'file' => $selected,
            'lines' => $lines,
            'search' => $search,
            'level' => $level,
            'sizeBytes' => 0,
            'modifiedAt' => null,
            'content' => '',
            'entries' => [],
            'truncated' => false,
        ];

        if ($selected === null) {
            return $payload;
        }

        $path = $this->resolvePath($selected);
        $size = (int) (@filesize($path) ?: 0);
        $modified = @filemtime($path) ?: null;
        $raw = $this->tail($path, $lines);
        $entries = $this->parseEntries($raw);

        if ($level !== null) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => ($entry['level'] ?? '') === $level,
            ));
        }

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => str_contains(mb_strtolower((string) ($entry['raw'] ?? '')), $needle),
            ));
        }

        $content = implode("\n", array_map(
            static fn (array $entry): string => (string) ($entry['raw'] ?? ''),
            $entries,
        ));

        $payload['sizeBytes'] = $size;
        $payload['modifiedAt'] = $modified ? date('c', $modified) : null;
        $payload['content'] = $content;
        $payload['entries'] = $entries;
        $payload['truncated'] = $size > 0 && strlen($raw) >= self::MAX_BYTES;

        return $payload;
    }

    public function clear(?string $file = null): array
    {
        $cleared = [];

        if ($file !== null && $file !== '') {
            $path = $this->resolvePath($file);
            $this->truncate($path);
            $cleared[] = basename($path);
        } else {
            foreach ($this->files() as $item) {
                $this->truncate($this->resolvePath($item['name']));
                $cleared[] = $item['name'];
            }
        }

        return $cleared;
    }

    private function resolveSelectedFile(?string $file, array $files): ?string
    {
        if ($file !== null && $file !== '') {
            $this->resolvePath($file);

            return basename($file);
        }

        return $files[0]['name'] ?? null;
    }

    private function resolvePath(string $file): string
    {
        $name = basename(str_replace('\\', '/', $file));
        if (! $this->isAllowedName($name)) {
            throw new InvalidArgumentException('Log file is not allowed.');
        }

        $directory = realpath($this->directory()) ?: $this->directory();
        $candidate = $directory.DIRECTORY_SEPARATOR.$name;
        $realDirectory = realpath($directory) ?: $directory;
        $normalized = str_replace('\\', '/', $candidate);
        $normalizedDir = rtrim(str_replace('\\', '/', $realDirectory), '/');

        if (! str_starts_with($normalized, $normalizedDir.'/')) {
            throw new InvalidArgumentException('Log file is not allowed.');
        }

        if (! is_file($candidate)) {
            throw new InvalidArgumentException('Log file was not found.');
        }

        $real = realpath($candidate);
        if ($real === false || ! str_starts_with(str_replace('\\', '/', $real), $normalizedDir.'/')) {
            throw new InvalidArgumentException('Log file was not found.');
        }

        return $real;
    }

    private function isAllowedName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]+\.log$/', $name);
    }

    private function truncate(string $path): void
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to clear the log file.');
        }

        fclose($handle);
    }

    private function tail(string $path, int $lineCount): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $buffer = '';
        $chunk = 8192;
        fseek($handle, 0, SEEK_END);
        $position = (int) ftell($handle);

        while ($position > 0 && substr_count($buffer, "\n") <= $lineCount) {
            $read = min($chunk, $position);
            $position -= $read;
            fseek($handle, $position);
            $chunkData = fread($handle, $read);
            if ($chunkData === false) {
                break;
            }
            $buffer = $chunkData.$buffer;
            if (strlen($buffer) >= self::MAX_BYTES) {
                $buffer = substr($buffer, -self::MAX_BYTES);
                break;
            }
        }

        fclose($handle);

        $lines = preg_split("/\r\n|\n|\r/", $buffer) ?: [];
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\n", array_slice($lines, -$lineCount));
    }

    /**
     * @return list<array{timestamp: string|null, environment: string|null, level: string, message: string, raw: string}>
     */
    private function parseEntries(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $entries = [];
        $current = null;
        $started = false;

        foreach (preg_split("/\r\n|\n|\r/", $content) ?: [] as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[^\]]*)\]\s+(\w+)\.(\w+):\s?(.*)$/', $line, $matches) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $started = true;
                $current = [
                    'timestamp' => $matches[1],
                    'environment' => $matches[2],
                    'level' => strtolower($matches[3]),
                    'message' => $matches[4],
                    'raw' => $line,
                ];

                continue;
            }

            if (! $started) {
                continue;
            }

            if ($current !== null) {
                $current['raw'] .= "\n".$line;
                $current['message'] .= "\n".$line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return $entries;
    }
}
