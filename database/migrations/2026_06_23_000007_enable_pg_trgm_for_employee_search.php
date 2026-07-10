<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME_COLUMNS = ['firstName', 'middleName', 'lastName', 'maidenName', 'code'];

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION employee_search_name(
                first_name text,
                middle_name text,
                last_name text,
                maiden_name text
            )
            RETURNS text
            LANGUAGE sql
            IMMUTABLE
            PARALLEL SAFE
            AS $$
                SELECT lower(trim(concat_ws(' ', first_name, middle_name, last_name, maiden_name)))
            $$
        SQL);

        foreach (self::NAME_COLUMNS as $column) {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS employee_{$column}_trgm_idx
                 ON employee USING gin (lower(COALESCE(\"{$column}\", ''::text)) gin_trgm_ops)"
            );
        }

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS employee_full_name_trgm_idx
            ON employee USING gin (
                employee_search_name("firstName", "middleName", "lastName", "maidenName") gin_trgm_ops
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS employee_full_name_trgm_idx');

        foreach (self::NAME_COLUMNS as $column) {
            DB::statement("DROP INDEX IF EXISTS employee_{$column}_trgm_idx");
        }

        DB::statement('DROP FUNCTION IF EXISTS employee_search_name(text, text, text, text)');
    }
};
