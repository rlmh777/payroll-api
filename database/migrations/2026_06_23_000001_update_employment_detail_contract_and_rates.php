<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employment_detail', 'totalRate')) {
            DB::statement('ALTER TABLE employment_detail RENAME COLUMN "totalRate" TO "yearlyRate"');
        }

        if (!Schema::hasColumn('employment_detail', 'jobTitle')) {
            Schema::table('employment_detail', function (Blueprint $table) {
                $table->string('jobTitle', 255)->nullable();
            });
        }

        DB::statement('ALTER TABLE employment_detail ALTER COLUMN "contractAgreementPath" DROP NOT NULL');
    }

    public function down(): void
    {
        if (Schema::hasColumn('employment_detail', 'yearlyRate')) {
            DB::statement('ALTER TABLE employment_detail RENAME COLUMN "yearlyRate" TO "totalRate"');
        }

        if (Schema::hasColumn('employment_detail', 'jobTitle')) {
            Schema::table('employment_detail', function (Blueprint $table) {
                $table->dropColumn('jobTitle');
            });
        }

        DB::statement('ALTER TABLE employment_detail ALTER COLUMN "contractAgreementPath" SET NOT NULL');
    }
};
