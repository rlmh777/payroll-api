<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        $settingsMenuId = DB::table('menus')
            ->where('route', '/settings')
            ->where('type', 'menu')
            ->value('id');

        if (!$settingsMenuId) {
            return;
        }

        $topLevelAccounts = DB::table('menus')
            ->where('route', '/accounts')
            ->where('type', 'menu')
            ->first();

        if ($topLevelAccounts) {
            DB::table('menus')->where('id', $topLevelAccounts->id)->delete();
        }

        $exists = DB::table('menus')->where('route', '/settings/accounts')->exists();
        if ($exists) {
            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $settingsMenuId,
            'title' => 'Accounts',
            'route' => '/settings/accounts',
            'icon' => 'fas fa-wallet',
            'permission' => 'view-accounts',
            'order' => 3,
            'type' => 'submenu',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')->where('route', '/settings/accounts')->delete();

        $exists = DB::table('menus')->where('route', '/accounts')->where('type', 'menu')->exists();
        if ($exists) {
            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => null,
            'title' => 'Accounts',
            'route' => '/accounts',
            'icon' => 'fas fa-wallet',
            'permission' => 'view-accounts',
            'order' => 3,
            'type' => 'menu',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
