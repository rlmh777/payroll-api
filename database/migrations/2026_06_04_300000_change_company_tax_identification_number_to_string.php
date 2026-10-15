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
        if (!Schema::hasTable('company') || !Schema::hasColumn('company', 'taxIdentificationNumber')) {
            return;
        }

        DB::statement(
            'ALTER TABLE company ALTER COLUMN "taxIdentificationNumber" TYPE varchar(255) USING "taxIdentificationNumber"::text'
        );

        DB::statement(
            'ALTER TABLE company ALTER COLUMN "taxIdentificationNumber" DROP NOT NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('company') || !Schema::hasColumn('company', 'taxIdentificationNumber')) {
            return;
        }

        DB::statement(
            'ALTER TABLE company ALTER COLUMN "taxIdentificationNumber" TYPE integer USING NULLIF("taxIdentificationNumber", \'\')::integer'
        );

        DB::statement(
            'ALTER TABLE company ALTER COLUMN "taxIdentificationNumber" SET NOT NULL'
        );
    }
};
