<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidate navigation under a single payroll module until multi-module split is ready.
     */
    public function up(): void
    {
        if (! Schema::hasTable('menus') || ! Schema::hasColumn('menus', 'module_code')) {
            return;
        }

        DB::table('menus')->update(['module_code' => 'payroll']);

        if (! Schema::hasTable('company_modules')) {
            return;
        }

        $companyIds = DB::table('company')->pluck('id');

        foreach ($companyIds as $companyId) {
            DB::table('company_modules')->updateOrInsert(
                ['company_id' => $companyId, 'module_code' => 'payroll'],
                ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        // Module assignments are restored by modules:sync-menus if needed.
    }
};
