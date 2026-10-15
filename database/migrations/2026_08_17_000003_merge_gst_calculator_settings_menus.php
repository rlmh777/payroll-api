<?php

use App\Models\Menu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        Menu::query()->where('route', '/settings/tax-calculator-rates')->delete();

        Menu::query()
            ->where('route', '/settings/tax-calculator-accounts')
            ->update(['title' => 'GST Calculator']);
    }

    public function down(): void
    {
        // Rates were merged into GST Calculator accounts; do not restore a separate menu.
    }
};
