<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')
            ->where('route', 'like', '/settings/general/%')
            ->orderBy('id')
            ->lazyById()
            ->each(function ($menu) {
                DB::table('menus')
                    ->where('id', $menu->id)
                    ->update([
                        'route' => str_replace('/settings/general/', '/settings/', $menu->route),
                    ]);
            });

        DB::table('menus')
            ->where('route', '/settings/general')
            ->update([
                'route' => '/settings',
                'order' => 1,
            ]);

        DB::table('menus')
            ->where('title', 'Organization')
            ->where('route', '/settings/organization')
            ->update(['order' => 2]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')
            ->where('route', 'like', '/settings/%')
            ->where('route', 'not like', '/settings/general/%')
            ->where('route', '!=', '/settings')
            ->whereIn('title', [
                'Country',
                'District',
                'Locality',
                'Institution',
                'Relationship',
                'Bank Account Type',
                'Define Working Hour Timesheet',
                'Calculation Mode',
                'Degree',
                'Department',
                'Work Site',
            ])
            ->orderBy('id')
            ->lazyById()
            ->each(function ($menu) {
                if (!str_starts_with((string) $menu->route, '/settings/')) {
                    return;
                }

                $suffix = substr((string) $menu->route, strlen('/settings/'));
                DB::table('menus')
                    ->where('id', $menu->id)
                    ->update([
                        'route' => '/settings/general/' . $suffix,
                    ]);
            });

        DB::table('menus')
            ->where('route', '/settings')
            ->where('title', 'General')
            ->update([
                'route' => '/settings/general',
                'order' => 2,
            ]);

        DB::table('menus')
            ->where('title', 'Organization')
            ->where('route', '/settings/organization')
            ->update(['order' => 1]);
    }
};
