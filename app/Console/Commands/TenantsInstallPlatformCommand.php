<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class TenantsInstallPlatformCommand extends Command
{
    protected $signature = 'tenants:install-platform';

    protected $description = 'Create the platform registry database and run its migrations';

    public function handle(): int
    {
        $database = (string) config('tenancy.platform_database');
        $this->createDatabaseIfMissing($database);

        $this->info("Running platform migrations on [{$database}]...");

        $exit = Artisan::call('migrate', [
            '--database' => 'platform',
            '--path' => 'database/migrations/platform',
            '--force' => true,
        ], $this->output);

        return $exit === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function createDatabaseIfMissing(string $database): void
    {
        $exists = DB::connection('pgsql')->selectOne(
            'SELECT 1 AS ok FROM pg_database WHERE datname = ?',
            [$database]
        );

        if ($exists) {
            $this->line("Platform database [{$database}] already exists.");

            return;
        }

        // CREATE DATABASE cannot run inside a transaction.
        DB::connection('pgsql')->getPdo()->exec(
            sprintf('CREATE DATABASE "%s"', str_replace('"', '""', $database))
        );
        $this->info("Created platform database [{$database}].");

        DB::purge('platform');
        DB::reconnect('platform');
    }
}
