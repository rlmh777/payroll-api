<?php

namespace App\Console\Commands;

use App\Enums\StorageDriver;
use App\Models\StorageSetting;
use App\Models\Tenant;
use App\Support\ConfiguredStorage;
use App\Support\TenantManager;
use Illuminate\Console\Command;
use Throwable;

class ConfigureStorageCommand extends Command
{
    protected $signature = 'storage:configure
        {--driver=azure : Storage driver (azure)}
        {--container= : Blob container or bucket (defaults to tenant slug)}
        {--account-name= : Azure storage account name or S3 access key}
        {--account-key= : Azure account key or S3 secret}
        {--endpoint= : Optional custom endpoint}
        {--prefix= : Optional path prefix}
        {--tenant= : Tenant slug (all enabled tenants when omitted)}
        {--force : Overwrite existing storage settings}';

    protected $description = 'Write File Storage settings from CLI flags or AZURE_STORAGE_* environment variables';

    public function handle(TenantManager $tenants, ConfiguredStorage $storage): int
    {
        $accountName = trim((string) ($this->option('account-name') ?: env('AZURE_STORAGE_ACCOUNT_NAME', '')));
        $accountKey = trim((string) ($this->option('account-key') ?: env('AZURE_STORAGE_ACCOUNT_KEY', '')));

        if ($accountName === '' || $accountKey === '') {
            $this->warn('Azure storage account name/key are not set; leaving File Storage settings unchanged.');

            return self::SUCCESS;
        }

        $driver = StorageDriver::tryFrom((string) $this->option('driver')) ?? StorageDriver::Azure;
        if ($driver === StorageDriver::Local) {
            $this->error('Use the File Storage settings page to switch back to the local disk.');

            return self::FAILURE;
        }

        $cliContainer = trim((string) $this->option('container'));
        $envContainer = trim((string) env('AZURE_STORAGE_CONTAINER', ''));
        $endpoint = trim((string) ($this->option('endpoint') ?: env('AZURE_STORAGE_ENDPOINT', ''))) ?: null;
        $prefix = trim((string) ($this->option('prefix') ?: env('AZURE_STORAGE_PREFIX', ''))) ?: null;
        $force = (bool) $this->option('force');

        $apply = function (?string $slug) use (
            $storage,
            $driver,
            $accountName,
            $accountKey,
            $cliContainer,
            $envContainer,
            $endpoint,
            $prefix,
            $force,
        ): void {
            $container = $cliContainer !== ''
                ? $cliContainer
                : ($slug ?: ($envContainer !== '' ? $envContainer : 'uploads'));

            $settings = StorageSetting::current();
            if ($settings->exists && $settings->driverEnum() !== StorageDriver::Local && ! $force) {
                $this->line(sprintf(
                    'Skipping %s (already %s / %s). Pass --force to overwrite.',
                    $slug ?: 'current database',
                    $settings->driverEnum()->value,
                    $settings->container,
                ));

                return;
            }

            $settings->fill([
                'driver' => $driver->value,
                'container' => $container,
                'account_name' => $accountName,
                'account_key' => $accountKey,
                'endpoint' => $endpoint,
                'region' => null,
                'use_path_style_endpoint' => false,
                'prefix' => $prefix,
            ])->save();
            $storage->forgetCachedDisk();

            $this->info(sprintf(
                'File Storage settings → %s container "%s"%s',
                $driver->value,
                $container,
                $slug ? " for tenant [{$slug}]" : '',
            ));
        };

        if ($tenants->enabled()) {
            $requested = trim((string) $this->option('tenant'));
            try {
                $query = Tenant::query()->where('enabled', true);
                if ($requested !== '') {
                    $query->where('slug', $tenants->normalizeSlug($requested));
                }
                $list = $query->orderBy('slug')->get();
            } catch (Throwable $exception) {
                $list = collect();
            }
            if ($list->isEmpty()) {
                $apply($tenants->normalizeSlug($requested !== '' ? $requested : (string) config('tenancy.default')));

                return self::SUCCESS;
            }

            $failed = 0;
            foreach ($list as $tenant) {
                try {
                    $tenants->initialize($tenant);
                    $apply($tenant->slug);
                } catch (Throwable $exception) {
                    $this->error("Failed for [{$tenant->slug}]: ".$exception->getMessage());
                    $failed++;
                }
            }
            $tenants->forget();

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $apply(null);

        return self::SUCCESS;
    }
}
