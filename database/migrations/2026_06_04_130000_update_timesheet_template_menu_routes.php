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
            ->where('route', '/settings/working-hours-timesheet')
            ->update([
                'title' => 'Timesheet Templates',
                'route' => '/settings/timesheet-templates',
                'permission' => 'view-timesheet-templates',
            ]);
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
            ->where('route', '/settings/timesheet-templates')
            ->update([
                'title' => 'Define Working Hour Timesheet',
                'route' => '/settings/working-hours-timesheet',
                'permission' => 'view-working-hours-timesheet',
            ]);
    }
};
