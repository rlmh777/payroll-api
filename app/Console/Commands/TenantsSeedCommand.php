<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\TenantManager;
use Database\Seeders\SafeCatalogSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class TenantsSeedCommand extends Command
{
    protected $signature = 'tenants:seed
        {slug? : Tenant slug (omit to seed all enabled tenants)}
        {--class= : Seeder class (defaults to SafeCatalogSeeder)}
        {--force : Force seeding in production}';

    protected $description = 'Run an insert-only catalog seeder for one or all tenant databases';

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

        $class = (string) ($this->option('class') ?: SafeCatalogSeeder::class);
        $failed = 0;
        foreach ($list as $tenant) {
            $this->info("Seeding tenant [{$tenant->slug}] ({$tenant->database}) with {$class}...");
            $tenants->initialize($tenant);

            $exit = Artisan::call('db:seed', [
                '--database' => 'tenant',
                '--class' => $class,
                '--force' => (bool) $this->option('force'),
            ], $this->output);

            if ($exit !== 0) {
                $this->error("Seeding failed for [{$tenant->slug}].");
                $failed++;
            }
        }

        $tenants->forget();

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
