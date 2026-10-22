<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE employee SET "internalId1" = NULL WHERE "internalId1" IS NOT NULL AND btrim("internalId1") = \'\'');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS employee_code_unique ON employee (lower(btrim(code)))');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS employee_internal_id1_unique ON employee (lower(btrim("internalId1"))) WHERE "internalId1" IS NOT NULL AND btrim("internalId1") <> \'\'');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS employee_internal_id1_unique');
        DB::statement('DROP INDEX IF EXISTS employee_code_unique');
    }
};
