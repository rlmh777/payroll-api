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

        Menu::query()
            ->where('route', '/settings/accounts/mapping')
            ->update(['route' => '/settings/account-mapping']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        Menu::query()
            ->where('route', '/settings/account-mapping')
            ->update(['route' => '/settings/accounts/mapping']);
    }
};
