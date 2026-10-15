<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE personal_relief ALTER COLUMN "startRange" TYPE NUMERIC(14, 2)');
        DB::statement('ALTER TABLE personal_relief ALTER COLUMN "endRange" TYPE NUMERIC(14, 2)');
        DB::statement('ALTER TABLE personal_relief ALTER COLUMN "personalRelief" TYPE NUMERIC(14, 2)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE personal_relief ALTER COLUMN "startRange" TYPE NUMERIC(10, 2)');
        DB::statement('ALTER TABLE personal_relief ALTER COLUMN "endRange" TYPE NUMERIC(10, 2)');
        DB::statement('ALTER TABLE personal_relief ALTER COLUMN "personalRelief" TYPE NUMERIC(10, 2)');
    }
};
