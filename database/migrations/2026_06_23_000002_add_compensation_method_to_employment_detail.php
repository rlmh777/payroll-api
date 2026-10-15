<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employment_detail', 'compensationMethod')) {
            Schema::table('employment_detail', function (Blueprint $table) {
                $table->string('compensationMethod', 32)->default('HOURLY');
                $table->boolean('requiresClocking')->default(true);
            });
        }

        DB::statement(<<<'SQL'
            UPDATE employment_detail
            SET "compensationMethod" = CASE
                WHEN COALESCE("hourlyRate", 0) > 0
                    AND (COALESCE("yearlyRate", 0) = 0 OR COALESCE("hourlyRate", 0) * 40 >= COALESCE("yearlyRate", 0))
                    THEN 'HOURLY'
                WHEN COALESCE("yearlyRate", 0) > 0 THEN 'BASE_SALARY'
                ELSE 'HOURLY'
            END
        SQL);

        DB::statement(<<<'SQL'
            UPDATE employment_detail
            SET "requiresClocking" = ("compensationMethod" = 'HOURLY')
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasColumn('employment_detail', 'compensationMethod')) {
            Schema::table('employment_detail', function (Blueprint $table) {
                $table->dropColumn(['compensationMethod', 'requiresClocking']);
            });
        }
    }
};
