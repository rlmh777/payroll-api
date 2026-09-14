<?php

namespace Tests\Unit\Workflow;

use App\Enums\LeavePaymentTreatment;
use App\Enums\LeaveStatusCode;
use App\Models\PipelineTemplate;
use App\Models\User;
use App\Modules\Workflow\Services\PipelineEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_advances_through_seeded_leave_steps(): void
    {
        $this->seed(\Database\Seeders\PipelineTemplateSeeder::class);

        $engine = app(PipelineEngine::class);
        $user = User::factory()->create();

        $subjectId = (string) \Illuminate\Support\Str::uuid();
        $started = $engine->start(PipelineEngine::SUBJECT_LEAVE, $subjectId, $user, 'leave_approval');

        $this->assertSame('in_progress', $started['instance']->status);
        $this->assertSame(LeaveStatusCode::PendingSupervisorApproval->value, $started['domain_status']);

        $hr = $engine->transition(PipelineEngine::SUBJECT_LEAVE, $subjectId, 'approve', $user);
        $this->assertFalse($hr['completed']);
        $this->assertSame(LeaveStatusCode::PendingHrApproval->value, $hr['domain_status']);

        $accounts = $engine->transition(PipelineEngine::SUBJECT_LEAVE, $subjectId, 'approve', $user);
        $this->assertFalse($accounts['completed']);
        $this->assertSame(LeaveStatusCode::PendingAccountsConfirmation->value, $accounts['domain_status']);

        $done = $engine->transition(PipelineEngine::SUBJECT_LEAVE, $subjectId, 'approve', $user);
        $this->assertTrue($done['completed']);
        $this->assertSame(LeaveStatusCode::Scheduled->value, $done['domain_status']);
        $this->assertSame('completed', $done['instance']->status);
    }

    public function test_template_exists_after_seed(): void
    {
        $this->seed(\Database\Seeders\PipelineTemplateSeeder::class);

        $template = PipelineTemplate::query()->where('key', 'leave_approval')->with('steps')->first();
        $this->assertNotNull($template);
        $this->assertCount(3, $template->steps);
        $this->assertSame('accounts', $template->steps->last()?->key);
        $this->assertTrue(PipelineTemplate::query()->where('key', 'timesheet_approval')->exists());
    }

    public function test_payment_treatment_excludes_already_paid_from_payroll(): void
    {
        $this->assertTrue(LeavePaymentTreatment::AlreadyPaid->shouldExcludeFromPayrollPay());
        $this->assertTrue(LeavePaymentTreatment::Unpaid->shouldExcludeFromPayrollPay());
        $this->assertFalse(LeavePaymentTreatment::PaidWithPayroll->shouldExcludeFromPayrollPay());
    }
}
