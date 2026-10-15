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
        $tableName = Schema::hasTable('timesheet_template')
            ? 'timesheet_template'
            : (Schema::hasTable('work_timesheet') ? 'work_timesheet' : null);

        if ($tableName === null) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (!Schema::hasColumn($tableName, 'day_schedules')) {
                $table->json('day_schedules')->nullable()->after('days');
            }
        });

        DB::table($tableName)->orderBy('id')->lazyById()->each(function ($row) use ($tableName) {
            if (!empty($row->day_schedules)) {
                return;
            }

            $days = json_decode($row->days ?? '[]', true) ?: [];
            $daySchedules = [];

            foreach ($days as $day) {
                $daySchedules[] = [
                    'day' => $day,
                    'start_time' => substr((string) $row->start_time, 0, 5),
                    'end_time' => substr((string) $row->end_time, 0, 5),
                    'include_lunch_hour' => ((int) $row->break_minutes) === 0,
                ];
            }

            DB::table($tableName)
                ->where('id', $row->id)
                ->update(['day_schedules' => json_encode($daySchedules)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = Schema::hasTable('timesheet_template')
            ? 'timesheet_template'
            : (Schema::hasTable('work_timesheet') ? 'work_timesheet' : null);

        if ($tableName === null) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (Schema::hasColumn($tableName, 'day_schedules')) {
                $table->dropColumn('day_schedules');
            }
        });
    }
};
