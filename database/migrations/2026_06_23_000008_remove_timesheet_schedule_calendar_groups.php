<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('calendar_groups')->whereIn('key', ['timesheets', 'schedules'])->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $now = now();

        DB::table('calendar_groups')->insert([
            [
                'id' => (string) str()->uuid(),
                'key' => 'timesheets',
                'name' => 'Timesheets',
                'color' => '#1E88E5',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) str()->uuid(),
                'key' => 'schedules',
                'name' => 'Schedules',
                'color' => '#546E7A',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
};
