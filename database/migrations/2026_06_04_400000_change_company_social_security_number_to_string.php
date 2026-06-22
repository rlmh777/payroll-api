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
        if (!Schema::hasTable('company') || !Schema::hasColumn('company', 'socialSecurityNumber')) {
            return;
        }

        $column = DB::selectOne("
            SELECT udt_name, is_nullable
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'company'
              AND column_name = 'socialSecurityNumber'
        ");

        if (!$column) {
            return;
        }

        if ($column->udt_name === 'bytea') {
            DB::statement(
                'ALTER TABLE company ALTER COLUMN "socialSecurityNumber" TYPE varchar(255) USING convert_from("socialSecurityNumber", \'UTF8\')'
            );
        } elseif ($column->udt_name !== 'varchar') {
            DB::statement(
                'ALTER TABLE company ALTER COLUMN "socialSecurityNumber" TYPE varchar(255) USING "socialSecurityNumber"::varchar(255)'
            );
        }

        if ($column->is_nullable === 'NO') {
            DB::statement(
                'ALTER TABLE company ALTER COLUMN "socialSecurityNumber" DROP NOT NULL'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('company') || !Schema::hasColumn('company', 'socialSecurityNumber')) {
            return;
        }

        $column = DB::selectOne("
            SELECT udt_name, is_nullable
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'company'
              AND column_name = 'socialSecurityNumber'
        ");

        if (!$column || $column->udt_name === 'bytea') {
            return;
        }

        DB::statement(
            'ALTER TABLE company ALTER COLUMN "socialSecurityNumber" TYPE bytea USING convert_to("socialSecurityNumber", \'UTF8\')'
        );

        if ($column->is_nullable === 'YES') {
            DB::statement(
                'ALTER TABLE company ALTER COLUMN "socialSecurityNumber" SET NOT NULL'
            );
        }
    }
};
