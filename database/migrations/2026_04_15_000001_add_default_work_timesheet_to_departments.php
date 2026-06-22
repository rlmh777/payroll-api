<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $templateTable = Schema::hasTable('timesheet_template')
            ? 'timesheet_template'
            : (Schema::hasTable('work_timesheet') ? 'work_timesheet' : null);

        $assignmentTable = Schema::hasTable('timesheet_template_department')
            ? 'timesheet_template_department'
            : (Schema::hasTable('work_timesheet_department') ? 'work_timesheet_department' : null);

        if ($templateTable === null || $assignmentTable === null) {
            return;
        }

        $templateForeignKey = $assignmentTable === 'timesheet_template_department'
            ? 'timesheet_template_id'
            : 'work_timesheet_id';

        $now = now();
        $defaultTemplate = DB::table($templateTable)->where('name', 'Default Template')->first()
            ?? DB::table($templateTable)->where('name', 'Default Timesheet')->first();
        $defaultTemplateId = $defaultTemplate?->id ?? (string) Str::uuid();

        if (!$defaultTemplate) {
            DB::table($templateTable)->insert([
                'id' => $defaultTemplateId,
                'name' => 'Default Template',
                'start_time' => '08:00',
                'end_time' => '17:00',
                'break_minutes' => 60,
                'days' => json_encode(['Mon', 'Tue', 'Wed', 'Thu', 'Fri']),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('department')
            ->leftJoin($assignmentTable, 'department.id', '=', "{$assignmentTable}.department_id")
            ->whereNull("{$assignmentTable}.id")
            ->select('department.id')
            ->orderBy('department.id')
            ->chunk(100, function ($departments) use ($defaultTemplateId, $now, $assignmentTable, $templateForeignKey) {
                $rows = $departments->map(fn ($department) => [
                    'id' => (string) Str::uuid(),
                    $templateForeignKey => $defaultTemplateId,
                    'department_id' => $department->id,
                    'effective_date' => $now->toDateString(),
                    'notes' => 'Default assignment created for department without a timesheet template.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows) {
                    DB::table($assignmentTable)->insert($rows);
                }
            });
    }

    public function down(): void
    {
        $assignmentTable = Schema::hasTable('timesheet_template_department')
            ? 'timesheet_template_department'
            : (Schema::hasTable('work_timesheet_department') ? 'work_timesheet_department' : null);

        if ($assignmentTable === null) {
            return;
        }

        DB::table($assignmentTable)
            ->where('notes', 'Default assignment created for department without a timesheet template.')
            ->delete();
    }
};
