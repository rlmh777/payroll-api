<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME_COLUMNS = ['firstName', 'middleName', 'lastName', 'maidenName'];

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach (self::NAME_COLUMNS as $column) {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS person_{$column}_trgm_idx
                 ON person USING gin (lower(COALESCE(\"{$column}\", ''::text)) gin_trgm_ops)"
            );
        }

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS person_full_name_trgm_idx
            ON person USING gin (
                employee_search_name("firstName", "middleName", "lastName", "maidenName") gin_trgm_ops
            )
        SQL);

        foreach (['firstName', 'middleName', 'lastName', 'maidenName'] as $column) {
            DB::statement("DROP INDEX IF EXISTS employee_{$column}_trgm_idx");
        }

        DB::statement('DROP INDEX IF EXISTS employee_full_name_trgm_idx');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS person_full_name_trgm_idx');

        foreach (self::NAME_COLUMNS as $column) {
            DB::statement("DROP INDEX IF EXISTS person_{$column}_trgm_idx");
        }

        foreach (['firstName', 'middleName', 'lastName', 'maidenName', 'code'] as $column) {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS employee_{$column}_trgm_idx
                 ON employee USING gin (lower(COALESCE(\"{$column}\", ''::text)) gin_trgm_ops)"
            );
        }
    }
};
