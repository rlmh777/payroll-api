<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        // is_core is informational only; all modules can be toggled per company.
        DB::table('modules')
            ->whereIn('code', ['core', 'admin'])
            ->update(['is_core' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        DB::table('modules')
            ->whereIn('code', ['core', 'admin'])
            ->update(['is_core' => true, 'updated_at' => now()]);
    }
};
