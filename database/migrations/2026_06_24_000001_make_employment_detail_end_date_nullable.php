<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE employment_detail ALTER COLUMN "endDate" DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE employment_detail SET "endDate" = "startDate" WHERE "endDate" IS NULL');
        DB::statement('ALTER TABLE employment_detail ALTER COLUMN "endDate" SET NOT NULL');
    }
};
