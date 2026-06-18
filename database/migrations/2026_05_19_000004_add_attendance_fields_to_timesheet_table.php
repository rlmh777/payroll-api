<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheet', 'clockInTime')) {
                $table->dateTime('clockInTime')->nullable()->after('date');
            }

            if (!Schema::hasColumn('timesheet', 'clockOutTime')) {
                $table->dateTime('clockOutTime')->nullable()->after('clockInTime');
            }

            if (!Schema::hasColumn('timesheet', 'overtimeHours')) {
                $table->decimal('overtimeHours', 8, 2)->default(0)->after('hoursWorked');
            }

            if (!Schema::hasColumn('timesheet', 'approvedBy')) {
                $table->foreignUuid('approvedBy')
                    ->nullable()
                    ->after('approvalStatus')
                    ->constrained('employee')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('timesheet', 'approvedAt')) {
                $table->dateTime('approvedAt')->nullable()->after('approvedBy');
            }

            if (!Schema::hasColumn('timesheet', 'remarks')) {
                $table->text('remarks')->nullable()->after('approvedAt');
            }

            $table->index('approvalStatus', 'timesheet_approval_status_idx');
            $table->index('approvedBy', 'timesheet_approved_by_idx');
            $table->index(['date', 'approvalStatus'], 'timesheet_date_approval_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            try {
                $table->dropIndex('timesheet_approval_status_idx');
            } catch (\Throwable $e) {
                // ignore when index does not exist
            }

            try {
                $table->dropIndex('timesheet_approved_by_idx');
            } catch (\Throwable $e) {
                // ignore when index does not exist
            }

            try {
                $table->dropIndex('timesheet_date_approval_status_idx');
            } catch (\Throwable $e) {
                // ignore when index does not exist
            }

            if (Schema::hasColumn('timesheet', 'approvedBy')) {
                try {
                    $table->dropForeign(['approvedBy']);
                } catch (\Throwable $e) {
                    // ignore when the key is already absent
                }
            }

            $columns = array_filter([
                Schema::hasColumn('timesheet', 'clockInTime') ? 'clockInTime' : null,
                Schema::hasColumn('timesheet', 'clockOutTime') ? 'clockOutTime' : null,
                Schema::hasColumn('timesheet', 'overtimeHours') ? 'overtimeHours' : null,
                Schema::hasColumn('timesheet', 'approvedBy') ? 'approvedBy' : null,
                Schema::hasColumn('timesheet', 'approvedAt') ? 'approvedAt' : null,
                Schema::hasColumn('timesheet', 'remarks') ? 'remarks' : null,
            ]);

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
