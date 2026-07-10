<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        $menus = DB::table('menus')
            ->where('route', '/timesheet')
            ->where('type', 'menu')
            ->whereNull('parent_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($menus->count() <= 1) {
            return;
        }

        $keepId = $menus->first()->id;

        DB::table('menus')
            ->where('route', '/timesheet')
            ->where('type', 'menu')
            ->whereNull('parent_id')
            ->where('id', '!=', $keepId)
            ->delete();
    }

    public function down(): void
    {
        // No-op: deduplication is not safely reversible.
    }
};
