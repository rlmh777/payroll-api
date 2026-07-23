<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('activitylog.table_name', 'activity_log');
        $connection = config('activitylog.database_connection');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($table)) {
            return;
        }

        $subjectType = DB::connection($connection)
            ->selectOne(
                "SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = 'subject_id'",
                [$table],
            )
            ?->data_type;

        if (in_array((string) $subjectType, ['character varying', 'varchar', 'uuid', 'text'], true)) {
            return;
        }

        DB::connection($connection)->statement('DROP INDEX IF EXISTS subject');
        DB::connection($connection)->statement('DROP INDEX IF EXISTS causer');

        DB::connection($connection)->statement("ALTER TABLE {$table} ALTER COLUMN subject_id TYPE varchar(36) USING subject_id::varchar(36)");
        DB::connection($connection)->statement("ALTER TABLE {$table} ALTER COLUMN causer_id TYPE varchar(36) USING causer_id::varchar(36)");

        $schema->table($table, function (Blueprint $blueprint) {
            $blueprint->index(['subject_type', 'subject_id'], 'subject');
            $blueprint->index(['causer_type', 'causer_id'], 'causer');
        });
    }

    public function down(): void
    {
        // Irreversible safely for UUID data; leave columns as strings.
    }
};
