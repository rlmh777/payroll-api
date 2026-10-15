<?php

use App\Models\Department;
use App\Models\TimesheetTemplate;
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
        $tableName = Schema::hasTable('timesheet_template')
            ? 'timesheet_template'
            : (Schema::hasTable('work_timesheet') ? 'work_timesheet' : null);

        if ($tableName === null) {
            return;
        }

        $assignmentTable = Schema::hasTable('timesheet_template_department')
            ? 'timesheet_template_department'
            : (Schema::hasTable('work_timesheet_department') ? 'work_timesheet_department' : null);

        $assignmentForeignKey = $assignmentTable === 'timesheet_template_department'
            ? 'timesheet_template_id'
            : 'work_timesheet_id';

        TimesheetTemplate::query()->each(function (TimesheetTemplate $template) use ($assignmentTable, $assignmentForeignKey) {
            $schedules = $template->day_schedules;

            if (!is_array($schedules) || $schedules === []) {
                return;
            }

            $needsBackfill = collect($schedules)->contains(
                fn (array $schedule) => ($schedule['department_id'] ?? null) === null,
            );

            if (!$needsBackfill) {
                return;
            }

            $departmentIds = $assignmentTable
                ? DB::table($assignmentTable)
                    ->where($assignmentForeignKey, $template->id)
                    ->pluck('department_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all()
                : [];

            if ($departmentIds === []) {
                $departmentIds = Department::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
            }

            if ($departmentIds === []) {
                return;
            }

            $updatedSchedules = [];

            foreach ($schedules as $schedule) {
                $departmentId = $schedule['department_id'] ?? null;

                if ($departmentId !== null && $departmentId !== '') {
                    $updatedSchedules[] = $schedule;
                    continue;
                }

                foreach ($departmentIds as $id) {
                    $updatedSchedules[] = array_merge($schedule, ['department_id' => $id]);
                }
            }

            $template->update([
                'day_schedules' => TimesheetTemplate::normalizeDaySchedules($updatedSchedules),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-reversible data migration.
    }
};
