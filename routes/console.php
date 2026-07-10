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
