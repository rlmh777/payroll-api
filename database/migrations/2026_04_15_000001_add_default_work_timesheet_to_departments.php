<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $defaultTimesheet = DB::table('work_timesheet')->where('name', 'Default Timesheet')->first();
        $defaultTimesheetId = $defaultTimesheet?->id ?? (string) Str::uuid();

        if (!$defaultTimesheet) {
            DB::table('work_timesheet')->insert([
                'id' => $defaultTimesheetId,
                'name' => 'Default Timesheet',
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
            ->leftJoin('work_timesheet_department', 'department.id', '=', 'work_timesheet_department.department_id')
            ->whereNull('work_timesheet_department.id')
            ->select('department.id')
            ->orderBy('department.id')
            ->chunk(100, function ($departments) use ($defaultTimesheetId, $now) {
                $rows = $departments->map(fn ($department) => [
                    'id' => (string) Str::uuid(),
                    'work_timesheet_id' => $defaultTimesheetId,
                    'department_id' => $department->id,
                    'effective_date' => $now->toDateString(),
                    'notes' => 'Default assignment created for department without a work timesheet.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows) {
                    DB::table('work_timesheet_department')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('work_timesheet_department')
            ->where('notes', 'Default assignment created for department without a work timesheet.')
            ->delete();
    }
};
