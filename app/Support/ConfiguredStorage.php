<?php

namespace App\Support;

use App\Enums\StorageDriver;
use App\Models\StorageSetting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ConfiguredStorage
{
    private ?Filesystem $disk = null;

    private ?string $resolvedDriver = null;

    public function forgetCachedDisk(): void
    {
        $this->disk = null;
        $this->resolvedDriver = null;
    }

    public function driver(): StorageDriver
    {
        return StorageSetting::current()->driverEnum();
    }

    public function disk(): Filesystem
    {
        if ($this->disk) {
            return $this->disk;
        }

        $settings = StorageSetting::current();
        $driver = $settings->driverEnum();

        try {
            $this->disk = match ($driver) {
                StorageDriver::Azure => $this->azureDisk($settings),
                StorageDriver::S3 => $this->s3Disk($settings),
                StorageDriver::Local => Storage::disk('public'),
            };
            $this->resolvedDriver = $driver->value;
        } catch (Throwable $exception) {
            report($exception);
            if ($driver !== StorageDriver::Local) {
                throw $exception;
            }
            $this->disk = Storage::disk('public');
            $this->resolvedDriver = StorageDriver::Local->value;
        }

        return $this->disk;
    }

    /**
     * Backups use the File Storage driver/container, but stay on the private local
     * disk when the admin setting is "local" so dump files are not web-accessible.
     */
    public function backupDisk(): Filesystem
    {
        if ($this->driver() === StorageDriver::Local) {
            return Storage::disk('local');
        }

        return $this->disk();
    }

    public function store(UploadedFile $file, string $directory, string $name): string
    {
        $directory = trim($directory, '/');
        $stored = $this->disk()->putFileAs($directory, $file, $name);

        return is_string($stored) && $stored !== ''
            ? $stored
            : $directory.'/'.$name;
    }

    public function delete(?string $path, ?Filesystem $disk = null): void
    {
        $disk ??= $this->disk();
        if ($path && $disk->exists($path)) {
            $disk->delete($path);
        }
    }

    public function exists(?string $path, ?Filesystem $disk = null): bool
    {
        $disk ??= $this->disk();

        return filled($path) && $disk->exists($path);
    }

    public function get(string $path): string
    {
        return (string) $this->disk()->get($path);
    }

    public function urlOrNull(?string $path): ?string
    {
        return filled($path) ? $this->url($path) : null;
    }

    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        $disk = $this->disk();
        $driver = $this->resolvedDriver ?? $this->driver()->value;

        if ($driver === StorageDriver::Local->value) {
            return asset('storage/'.$path);
        }

        try {
            return $disk->temporaryUrl($path, now()->addHour());
        } catch (Throwable) {
            return $disk->url($path);
        }
    }

    public function putFromLocal(string $destination, string $localPath, ?Filesystem $disk = null): void
    {
        if (! is_file($localPath) || filesize($localPath) === 0) {
            throw new \RuntimeException('Local file is missing or empty.');
        }

        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to read local file.');
        }

        try {
            $stored = ($disk ?? $this->disk())->put($destination, $stream);
            if ($stored === false) {
                throw new \RuntimeException('Failed to store file on the configured disk.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function copyToLocal(string $path, ?Filesystem $disk = null): string
    {
        $disk ??= $this->disk();
        if (! $disk->exists($path)) {
            throw new \RuntimeException('File is missing from storage.');
        }

        $localPath = sys_get_temp_dir().'/payroll_storage_'.uniqid('', true);
        $stream = $disk->readStream($path);
        if ($stream === false || $stream === null) {
            throw new \RuntimeException('Unable to read file from storage.');
        }

        $out = fopen($localPath, 'wb');
        if ($out === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new \RuntimeException('Unable to create a temporary file.');
        }

        try {
            stream_copy_to_stream($stream, $out);
        } finally {
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $localPath;
    }

    /**
     * @return array{ok: bool, message: string, driver: string}
     */
    public function testConnection(?StorageSetting $settings = null): array
    {
        $settings ??= StorageSetting::current();
        $driver = $settings->driverEnum();

        try {
            $disk = match ($driver) {
                StorageDriver::Azure => $this->azureDisk($settings),
                StorageDriver::S3 => $this->s3Disk($settings),
                StorageDriver::Local => Storage::disk('public'),
            };
            $probe = 'storage-probe/'.uniqid('ok_', true).'.txt';
            $disk->put($probe, 'ok');
            $disk->delete($probe);

            return [
                'ok' => true,
                'message' => $driver === StorageDriver::Local
                    ? 'Using local container disk (storage/app/public).'
                    : 'Connected to '.$driver->value.' container "'.($settings->container ?: 'uploads').'".',
                'driver' => $driver->value,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'driver' => $driver->value,
            ];
        }
    }

    private function azureDisk(StorageSetting $settings): Filesystem
    {
        if (! filled($settings->account_name) || ! filled($settings->account_key) || ! filled($settings->container)) {
            throw new \InvalidArgumentException('Azure Blob Storage needs account name, account key, and container.');
        }

        $config = [
            'driver' => 'azure-storage-blob',
            'credential' => 'shared_key',
            'account_name' => $settings->account_name,
            'account_key' => $settings->account_key,
            'container' => $settings->container,
            'prefix' => (string) ($settings->prefix ?? ''),
            'throw' => true,
        ];

        if (filled($settings->endpoint)) {
            $config['endpoint'] = rtrim((string) $settings->endpoint, '/');
        }

        return Storage::build($config);
    }

    private function s3Disk(StorageSetting $settings): Filesystem
    {
        if (! filled($settings->account_name) || ! filled($settings->account_key) || ! filled($settings->container)) {
            throw new \InvalidArgumentException('S3 storage needs access key, secret, and bucket/container.');
        }

        return Storage::build([
            'driver' => 's3',
            'key' => $settings->account_name,
            'secret' => $settings->account_key,
            'region' => $settings->region ?: 'us-east-1',
            'bucket' => $settings->container,
            'endpoint' => $settings->endpoint ?: null,
            'use_path_style_endpoint' => (bool) $settings->use_path_style_endpoint,
            'root' => (string) ($settings->prefix ?? ''),
            'throw' => true,
        ]);
    }
}
