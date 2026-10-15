<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Insert-only catalog seed for deployed environments.
 * Creates missing menus, modules, and permissions without replacing tenant data.
 */
class SafeCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            MenuSeeder::class,
            ModuleSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
