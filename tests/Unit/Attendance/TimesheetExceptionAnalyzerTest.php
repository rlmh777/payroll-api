<?php

namespace Tests\Unit\Attendance;

use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\TimesheetExceptionAnalyzer;
use App\Modules\Hr\Services\Attendance\TimesheetOverlapValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TimesheetExceptionAnalyzerTest extends TestCase
{
    private TimesheetExceptionAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new TimesheetExceptionAnalyzer(new TimesheetOverlapValidator());
    }

    #[Test]
    public function it_flags_duplicate_rounded_clock_times_for_the_same_day(): void
    {
        $first = $this->timesheet('2026-06-24', '08:00:00', '12:00:00');
        $second = $this->timesheet('2026-06-24', '08:07:00', '12:08:00');
        $second->roundOffClockInTime = '2026-06-24 08:00:00';
        $second->roundOffClockOutTime = '2026-06-24 12:00:00';
        $sameDay = collect([$first, $second]);

        $exceptions = $this->analyzer->analyze($first, $sameDay);

        $this->assertTrue(collect($exceptions)->contains(
            fn (array $exception) => $exception['code'] === 'duplicate_rounded_clock_in',
        ));
        $this->assertTrue(collect($exceptions)->contains(
            fn (array $exception) => $exception['code'] === 'duplicate_rounded_clock_out',
        ));
    }

    #[Test]
    public function it_flags_excessive_daily_hours(): void
    {
        $timesheet = $this->timesheet('2026-06-24', '06:00:00', '23:00:00');
        $timesheet->hoursWorked = 17;
        $timesheet->clockedHoursWorked = 17;

        $exceptions = $this->analyzer->analyze($timesheet, collect([$timesheet]));

        $this->assertTrue(collect($exceptions)->contains(
            fn (array $exception) => $exception['code'] === 'excessive_daily_hours',
        ));
    }

    #[Test]
    public function it_includes_processing_remarks_as_exceptions(): void
    {
        $timesheet = $this->timesheet('2026-06-24', '08:00:00', '16:00:00');
        $timesheet->remarks = 'Consecutive punch-in records detected.';

        $exceptions = $this->analyzer->analyze($timesheet, collect([$timesheet]));

        $this->assertSame('processing_issue', $exceptions[0]['code']);
        $this->assertStringContainsString('Consecutive punch-in', $exceptions[0]['message']);
    }

    private function timesheet(string $date, string $roundIn, string $roundOut): Timesheet
    {
        return new Timesheet([
            'id' => (string) Str::uuid(),
            'employeeId' => (string) Str::uuid(),
            'date' => $date,
            'clockInTime' => "{$date} {$roundIn}",
            'clockOutTime' => "{$date} {$roundOut}",
            'roundOffClockInTime' => "{$date} {$roundIn}",
            'roundOffClockOutTime' => "{$date} {$roundOut}",
            'hoursWorked' => 8,
            'clockedHoursWorked' => 8,
        ]);
    }
}
