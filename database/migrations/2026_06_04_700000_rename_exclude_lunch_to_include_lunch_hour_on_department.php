<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('department')) {
            return;
        }

        if (!Schema::hasColumn('department', 'includeLunchHour')) {
            Schema::table('department', function (Blueprint $table) {
                $table->boolean('includeLunchHour')->default(true)->after('totalWeeklyHoursBeforeOvertime');
            });
        }

        if (Schema::hasColumn('department', 'excludeLunch')) {
            DB::statement(
                'UPDATE department SET "includeLunchHour" = NOT COALESCE("excludeLunch", false)'
            );

            Schema::table('department', function (Blueprint $table) {
                $table->dropColumn('excludeLunch');
            });
        } else {
            DB::table('department')
                ->whereNull('includeLunchHour')
                ->update(['includeLunchHour' => true]);
        }

        DB::statement('ALTER TABLE department ALTER COLUMN "includeLunchHour" SET DEFAULT true');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('department')) {
            return;
        }

        if (!Schema::hasColumn('department', 'excludeLunch')) {
            Schema::table('department', function (Blueprint $table) {
                $table->boolean('excludeLunch')->default(false)->after('totalWeeklyHoursBeforeOvertime');
            });
        }

        if (Schema::hasColumn('department', 'includeLunchHour')) {
            DB::statement(
                'UPDATE department SET "excludeLunch" = NOT COALESCE("includeLunchHour", true)'
            );

            Schema::table('department', function (Blueprint $table) {
                $table->dropColumn('includeLunchHour');
            });
        }
    }
};
