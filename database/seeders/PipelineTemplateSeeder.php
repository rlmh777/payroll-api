<?php

namespace Database\Seeders;

use App\Enums\LeaveStatusCode;
use App\Enums\PipelineAssigneeType;
use App\Models\PipelineTemplate;
use App\Modules\Workflow\Services\PipelineEngine;
use Illuminate\Database\Seeder;

class PipelineTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedLeaveApproval();
        $this->seedTimesheetApproval();
    }

    private function seedLeaveApproval(): void
    {
        $template = PipelineTemplate::query()->updateOrCreate(
            ['key' => 'leave_approval'],
            [
                'name' => 'Leave approval',
                'subject_type' => PipelineEngine::SUBJECT_LEAVE,
                'description' => 'Leave approval: supervisor, HR, then accounts payment confirmation.',
                'completion_status' => LeaveStatusCode::Scheduled->value,
                'rejection_status' => LeaveStatusCode::Rejected->value,
                'cancellation_status' => LeaveStatusCode::Cancelled->value,
                'is_active' => true,
            ],
        );

        $template->steps()->delete();

        $template->steps()->createMany([
            [
                'key' => 'supervisor',
                'label' => 'Supervisor approval',
                'sort_order' => 1,
                'assignee_type' => PipelineAssigneeType::Supervisor->value,
                'assignee_role' => null,
                'domain_status' => LeaveStatusCode::PendingSupervisorApproval->value,
                'actions' => ['approve', 'reject', 'cancel'],
                'ui_config' => ['mode' => 'review'],
            ],
            [
                'key' => 'hr',
                'label' => 'HR approval',
                'sort_order' => 2,
                'assignee_type' => PipelineAssigneeType::Admin->value,
                'assignee_role' => null,
                'domain_status' => LeaveStatusCode::PendingHrApproval->value,
                'actions' => ['approve', 'reject', 'cancel'],
                'ui_config' => ['mode' => 'review'],
            ],
            [
                'key' => 'accounts',
                'label' => 'Accounts payment confirmation',
                'sort_order' => 3,
                'assignee_type' => PipelineAssigneeType::Role->value,
                'assignee_role' => 'accountant',
                'domain_status' => LeaveStatusCode::PendingAccountsConfirmation->value,
                'actions' => ['approve', 'reject', 'cancel'],
                'ui_config' => [
                    'mode' => 'payment_confirmation',
                    'paymentTreatments' => ['unpaid', 'paid_with_payroll', 'already_paid'],
                ],
            ],
        ]);
    }

    private function seedTimesheetApproval(): void
    {
        $template = PipelineTemplate::query()->updateOrCreate(
            ['key' => 'timesheet_approval'],
            [
                'name' => 'Timesheet approval',
                'subject_type' => PipelineEngine::SUBJECT_TIMESHEET,
                'description' => 'Default timesheet approval: supervisor, then department head.',
                'completion_status' => 'APPROVED',
                'rejection_status' => 'REJECTED',
                'cancellation_status' => 'PENDING',
                'is_active' => true,
            ],
        );

        $template->steps()->delete();

        $template->steps()->createMany([
            [
                'key' => 'supervisor',
                'label' => 'Supervisor approval',
                'sort_order' => 1,
                'assignee_type' => PipelineAssigneeType::Supervisor->value,
                'domain_status' => 'PENDING_SUPERVISOR',
                'actions' => ['approve', 'reject'],
                'ui_config' => ['mode' => 'review'],
            ],
            [
                'key' => 'department_head',
                'label' => 'Department head approval',
                'sort_order' => 2,
                'assignee_type' => PipelineAssigneeType::DepartmentHead->value,
                'domain_status' => 'PENDING',
                'actions' => ['approve', 'reject'],
                'ui_config' => ['mode' => 'review'],
            ],
        ]);
    }
}
