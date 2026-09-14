<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantsCreateCommand extends Command
{
    protected $signature = 'tenants:create
        {slug : Tenant slug (e.g. chaacreek)}
        {--name= : Display name}
        {--database= : Existing database name to attach (skip CREATE DATABASE)}
        {--migrate : Run tenant migrations after create}
        {--seed : Run DatabaseSeeder after migrate}
        {--force : Recreate registry row if slug already exists}';

    protected $description = 'Register a company tenant on the shared Postgres server';

    public function handle(TenantManager $tenants): int
    {
        if (! $tenants->enabled() && ! $this->confirm('Tenancy is disabled. Continue registering the tenant anyway?', true)) {
            return self::FAILURE;
        }

        $slug = $tenants->normalizeSlug((string) $this->argument('slug'));
        if ($slug === '') {
            $this->error('Invalid slug.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: Str::title(str_replace('-', ' ', $slug))));
        $database = trim((string) ($this->option('database') ?: $tenants->databaseNameForSlug($slug)));

        $existing = Tenant::query()->where('slug', $slug)->first();
        if ($existing && ! $this->option('force')) {
            $this->error("Tenant [{$slug}] already exists. Use --force to update.");

            return self::FAILURE;
        }

        $this->ensureDatabaseExists($database, create: ! $this->option('database'));

        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'id' => $existing?->id ?? (string) Str::uuid(),
                'name' => $name,
                'database' => $database,
                'enabled' => true,
                'meta' => [
                    'created_via' => 'tenants:create',
                ],
            ]
        );

        $this->info("Registered tenant [{$tenant->slug}] → database [{$tenant->database}]");

        if ($this->option('migrate') || $this->option('seed')) {
            $params = ['slug' => $slug, '--force' => true];
            if ($this->option('seed')) {
                $params['--seed'] = true;
            }
            $exit = Artisan::call('tenants:migrate', $params, $this->output);
            if ($exit !== 0) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function ensureDatabaseExists(string $database, bool $create): void
    {
        $exists = DB::connection('pgsql')->selectOne(
            'SELECT 1 AS ok FROM pg_database WHERE datname = ?',
            [$database]
        );

        if ($exists) {
            $this->line("Database [{$database}] already exists.");

            return;
        }

        if (! $create) {
            $this->error("Database [{$database}] was not found.");
            throw new \RuntimeException("Database [{$database}] was not found.");
        }

        DB::connection('pgsql')->statement(
            sprintf('CREATE DATABASE "%s"', str_replace('"', '""', $database))
        );
        $this->info("Created database [{$database}].");
    }
}
