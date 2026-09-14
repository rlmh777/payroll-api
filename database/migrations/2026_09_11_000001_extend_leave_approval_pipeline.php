<?php

use App\Enums\LeaveStatusCode;
use App\Enums\PipelineAssigneeType;
use App\Models\PipelineTemplate;
use App\Modules\Workflow\Services\PipelineEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('leave_status')) {
            $statuses = [
                [
                    'code' => LeaveStatusCode::PendingHrApproval->value,
                    'name' => 'Pending HR approval',
                    'sortOrder' => 32,
                    'isTerminal' => false,
                    'requiresSupervisor' => false,
                ],
                [
                    'code' => LeaveStatusCode::PendingAccountsConfirmation->value,
                    'name' => 'Pending accounts confirmation',
                    'sortOrder' => 35,
                    'isTerminal' => false,
                    'requiresSupervisor' => false,
                ],
            ];

            foreach ($statuses as $status) {
                $exists = DB::table('leave_status')->where('code', $status['code'])->exists();
                if ($exists) {
                    continue;
                }

                DB::table('leave_status')->insert(array_merge($status, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        if (Schema::hasTable('employee_leave')) {
            Schema::table('employee_leave', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_leave', 'paymentTreatment')) {
                    $table->string('paymentTreatment', 32)->nullable()->after('multiplier');
                }
                if (! Schema::hasColumn('employee_leave', 'paymentConfirmedAt')) {
                    $table->timestamp('paymentConfirmedAt')->nullable()->after('paymentTreatment');
                }
                if (! Schema::hasColumn('employee_leave', 'paymentConfirmedByUserId')) {
                    $table->foreignUuid('paymentConfirmedByUserId')
                        ->nullable()
                        ->after('paymentConfirmedAt')
                        ->constrained('users')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('employee_leave', 'paidInPayrollRunId')) {
                    $table->uuid('paidInPayrollRunId')->nullable()->after('paymentConfirmedByUserId');
                    $table->foreign('paidInPayrollRunId')
                        ->references('id')
                        ->on('payroll_runs')
                        ->nullOnDelete();
                }
            });
        }

        $this->refreshLeaveApprovalPipeline();
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_leave')) {
            Schema::table('employee_leave', function (Blueprint $table) {
                if (Schema::hasColumn('employee_leave', 'paidInPayrollRunId')) {
                    $table->dropForeign(['paidInPayrollRunId']);
                    $table->dropColumn('paidInPayrollRunId');
                }
                if (Schema::hasColumn('employee_leave', 'paymentConfirmedByUserId')) {
                    $table->dropForeign(['paymentConfirmedByUserId']);
                    $table->dropColumn('paymentConfirmedByUserId');
                }
                if (Schema::hasColumn('employee_leave', 'paymentConfirmedAt')) {
                    $table->dropColumn('paymentConfirmedAt');
                }
                if (Schema::hasColumn('employee_leave', 'paymentTreatment')) {
                    $table->dropColumn('paymentTreatment');
                }
            });
        }

        if (Schema::hasTable('leave_status')) {
            DB::table('leave_status')->whereIn('code', [
                LeaveStatusCode::PendingHrApproval->value,
                LeaveStatusCode::PendingAccountsConfirmation->value,
            ])->delete();
        }
    }

    private function refreshLeaveApprovalPipeline(): void
    {
        if (! Schema::hasTable('pipeline_templates') || ! Schema::hasTable('pipeline_template_steps')) {
            return;
        }

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
};
