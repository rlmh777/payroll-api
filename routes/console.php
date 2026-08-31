<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('timesheets:process')
    ->dailyAt(config('attendance.timesheet_processing.schedule_time', '02:00'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('timesheets:apply-payroll-locks')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

foreach (config('database-backup.schedule_times', []) as $time) {
    if (! is_string($time) || $time === '') {
        continue;
    }

    Schedule::command('db:backup')
        ->dailyAt($time)
        ->name('db-backup-'.$time)
        ->withoutOverlapping()
        ->onOneServer();
}
