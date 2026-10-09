<?php

namespace Tests\Unit\Hr;

use App\Models\HrSetting;
use App\Modules\Hr\Services\ContractExpiryReminderService;
use App\Support\ConfiguredMail;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ContractExpiryReminderOffsetTest extends TestCase
{
    public function test_default_offsets_match_three_months_one_month_one_week_one_day(): void
    {
        $this->assertSame([
            ['value' => 3, 'unit' => 'months'],
            ['value' => 1, 'unit' => 'months'],
            ['value' => 1, 'unit' => 'weeks'],
            ['value' => 1, 'unit' => 'days'],
        ], HrSetting::defaultOffsets());

        $this->assertSame('3 months', HrSetting::offsetLabel(['value' => 3, 'unit' => 'months']));
        $this->assertSame('1 week', HrSetting::offsetLabel(['value' => 1, 'unit' => 'weeks']));
    }

    public function test_offset_is_due_on_the_reminder_day_and_one_day_late(): void
    {
        $service = new ContractExpiryReminderService($this->createStub(ConfiguredMail::class));
        $end = Carbon::parse('2026-10-08');
        $offset = ['value' => 1, 'unit' => 'months'];

        $this->assertTrue($service->offsetIsDue($end, $offset, Carbon::parse('2026-09-08')));
        $this->assertTrue($service->offsetIsDue($end, $offset, Carbon::parse('2026-09-09')));
        $this->assertFalse($service->offsetIsDue($end, $offset, Carbon::parse('2026-09-07')));
        $this->assertFalse($service->offsetIsDue($end, $offset, Carbon::parse('2026-10-08')));
    }

    public function test_three_month_and_one_day_windows(): void
    {
        $service = new ContractExpiryReminderService($this->createStub(ConfiguredMail::class));
        $end = Carbon::parse('2026-07-07');

        $this->assertTrue($service->offsetIsDue($end, ['value' => 3, 'unit' => 'months'], Carbon::parse('2026-04-07')));
        $this->assertTrue($service->offsetIsDue($end, ['value' => 1, 'unit' => 'days'], Carbon::parse('2026-07-06')));
        $this->assertTrue($service->offsetIsDue($end, ['value' => 1, 'unit' => 'weeks'], Carbon::parse('2026-06-30')));
    }
}
