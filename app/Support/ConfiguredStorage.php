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

    public function store(UploadedFile $file, string $directory, string $name): string
    {
        $directory = trim($directory, '/');
        $stored = $this->disk()->putFileAs($directory, $file, $name);

        return is_string($stored) && $stored !== ''
            ? $stored
            : $directory.'/'.$name;
    }

    public function delete(?string $path): void
    {
        if ($path && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    public function exists(?string $path): bool
    {
        return filled($path) && $this->disk()->exists($path);
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

        return Storage::build([
            'driver' => 'azure-storage-blob',
            'account_name' => $settings->account_name,
            'account_key' => $settings->account_key,
            'container' => $settings->container,
            'prefix' => (string) ($settings->prefix ?? ''),
            'endpoint' => $settings->endpoint ?: null,
            'throw' => true,
        ]);
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
