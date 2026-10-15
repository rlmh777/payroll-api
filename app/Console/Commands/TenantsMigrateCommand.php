<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class TenantsMigrateCommand extends Command
{
    protected $signature = 'tenants:migrate
        {slug? : Tenant slug (omit to migrate all enabled tenants)}
        {--seed : Run DatabaseSeeder after migrate}
        {--force : Force migrations in production}';

    protected $description = 'Run migrations for one or all tenant databases';

    public function handle(TenantManager $tenants): int
    {
        $slug = $this->argument('slug');
        $query = Tenant::query()->where('enabled', true);
        if ($slug) {
            $query->where('slug', $tenants->normalizeSlug((string) $slug));
        }

        $list = $query->orderBy('slug')->get();
        if ($list->isEmpty()) {
            $this->warn('No matching tenants found.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($list as $tenant) {
            $this->info("Migrating tenant [{$tenant->slug}] ({$tenant->database})...");
            $tenants->initialize($tenant);

            $exit = Artisan::call('migrate', [
                '--database' => 'tenant',
                '--force' => (bool) $this->option('force'),
            ], $this->output);

            if ($exit !== 0) {
                $this->error("Migration failed for [{$tenant->slug}].");
                $failed++;
                continue;
            }

            if ($this->option('seed')) {
                $seedExit = Artisan::call('db:seed', [
                    '--database' => 'tenant',
                    '--force' => true,
                ], $this->output);
                if ($seedExit !== 0) {
                    $this->error("Seeding failed for [{$tenant->slug}].");
                    $failed++;
                }
            }
        }

        $tenants->forget();

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
