<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class TenantsListCommand extends Command
{
    protected $signature = 'tenants:list';

    protected $description = 'List registered tenants on the platform database';

    public function handle(): int
    {
        $rows = Tenant::query()
            ->orderBy('slug')
            ->get(['slug', 'name', 'database', 'enabled'])
            ->map(fn (Tenant $tenant) => [
                $tenant->slug,
                $tenant->name,
                $tenant->database,
                $tenant->enabled ? 'yes' : 'no',
            ])
            ->all();

        if ($rows === []) {
            $this->warn('No tenants registered. Run tenants:install-platform then tenants:create.');

            return self::SUCCESS;
        }

        $this->table(['slug', 'name', 'database', 'enabled'], $rows);

        return self::SUCCESS;
    }
}
