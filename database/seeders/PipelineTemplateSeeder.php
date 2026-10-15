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
        $this->seedFormReview();
        $this->seedEmployeeAppraisal();
        $this->seedEmployeeProfileChange();
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

    private function seedFormReview(): void
    {
        $template = PipelineTemplate::query()->updateOrCreate(
            ['key' => 'form_review'],
            [
                'name' => 'Form review',
                'subject_type' => PipelineEngine::SUBJECT_FORM_RESPONSE,
                'description' => 'Supervisor reviews a submitted form, then approves or rejects it.',
                'completion_status' => 'approved',
                'rejection_status' => 'rejected',
                'cancellation_status' => 'cancelled',
                'is_active' => true,
            ],
        );

        $template->steps()->delete();
        $template->steps()->create([
            'key' => 'supervisor',
            'label' => 'Supervisor review',
            'sort_order' => 1,
            'assignee_type' => PipelineAssigneeType::Supervisor->value,
            'domain_status' => 'pending_review',
            'actions' => ['approve', 'reject'],
            'ui_config' => ['mode' => 'review'],
        ]);
    }

    private function seedEmployeeAppraisal(): void
    {
        $template = PipelineTemplate::query()->updateOrCreate(
            ['key' => 'employee_appraisal'],
            [
                'name' => 'Employee appraisal',
                'subject_type' => PipelineEngine::SUBJECT_APPRAISAL,
                'description' => 'Supervisor, department head, HR (renew/increment), then accounts.',
                'completion_status' => 'approved',
                'rejection_status' => 'rejected',
                'cancellation_status' => 'cancelled',
                'is_active' => true,
            ],
        );

        $template->steps()->delete();
        $template->steps()->createMany([
            [
                'key' => 'supervisor',
                'label' => 'Supervisor review',
                'sort_order' => 1,
                'assignee_type' => PipelineAssigneeType::Supervisor->value,
                'domain_status' => 'pending_supervisor',
                'actions' => ['approve', 'reject', 'return'],
                'ui_config' => ['mode' => 'review'],
            ],
            [
                'key' => 'department_head',
                'label' => 'Head of department',
                'sort_order' => 2,
                'assignee_type' => PipelineAssigneeType::DepartmentHead->value,
                'domain_status' => 'pending_department_head',
                'actions' => ['approve', 'reject', 'return'],
                'ui_config' => ['mode' => 'review'],
            ],
            [
                'key' => 'hr',
                'label' => 'HR decision',
                'sort_order' => 3,
                'assignee_type' => PipelineAssigneeType::Admin->value,
                'domain_status' => 'pending_hr',
                'actions' => ['approve', 'reject', 'return'],
                'ui_config' => [
                    'mode' => 'hr_decision',
                    'fields' => ['renew_contract', 'increment'],
                ],
            ],
            [
                'key' => 'accounts',
                'label' => 'Accounts confirmation',
                'sort_order' => 4,
                'assignee_type' => PipelineAssigneeType::Role->value,
                'assignee_role' => 'accountant',
                'domain_status' => 'pending_accounts',
                'actions' => ['approve', 'return'],
                'ui_config' => ['mode' => 'accounts_confirmation'],
            ],
        ]);
    }

    private function seedEmployeeProfileChange(): void
    {
        $template = PipelineTemplate::query()->updateOrCreate(
            ['key' => 'employee_profile_change'],
            [
                'name' => 'Employee profile change',
                'subject_type' => PipelineEngine::SUBJECT_PROFILE_CHANGE,
                'description' => 'HR reviews address, phone, and email updates. Add supervisor or other steps in Admin → Pipelines if your company needs them first.',
                'completion_status' => 'approved',
                'rejection_status' => 'rejected',
                'cancellation_status' => 'cancelled',
                'is_active' => true,
            ],
        );

        if ($template->steps()->exists()) {
            return;
        }

        $template->steps()->create([
            'key' => 'hr',
            'label' => 'HR approval',
            'sort_order' => 1,
            'assignee_type' => PipelineAssigneeType::Admin->value,
            'domain_status' => 'pending_hr',
            'actions' => ['approve', 'reject', 'return', 'cancel'],
            'ui_config' => ['mode' => 'review'],
        ]);
    }
}
