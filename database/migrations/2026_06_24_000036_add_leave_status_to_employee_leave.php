<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_leave', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_leave', 'leaveStatusId')) {
                $table->foreignId('leaveStatusId')
                    ->nullable()
                    ->after('multiplier')
                    ->constrained('leave_status')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('employee_leave', 'statusNote')) {
                $table->text('statusNote')->nullable()->after('leaveStatusId');
            }
        });

        $statusIds = DB::table('leave_status')->pluck('id', 'code');

        DB::table('employee_leave')->orderBy('id')->chunkById(200, function ($rows) use ($statusIds) {
            foreach ($rows as $row) {
                $legacy = strtolower((string) ($row->approvalStatus ?? 'pending'));
                $code = match ($legacy) {
                    'approved' => Carbon::parse($row->endDate)->endOfDay()->lt(now())
                        ? 'TAKEN'
                        : 'SCHEDULED',
                    'rejected' => 'REJECTED',
                    'cancelled' => 'CANCELLED',
                    default => 'PENDING_SUPERVISOR_APPROVAL',
                };

                DB::table('employee_leave')->where('id', $row->id)->update([
                    'leaveStatusId' => $statusIds[$code] ?? $statusIds['PENDING_SUPERVISOR_APPROVAL'],
                ]);
            }
        });

        $defaultStatusId = $statusIds['PENDING_SUPERVISOR_APPROVAL'] ?? null;

        Schema::table('employee_leave', function (Blueprint $table) use ($defaultStatusId) {
            if ($defaultStatusId) {
                DB::statement('UPDATE employee_leave SET "leaveStatusId" = ? WHERE "leaveStatusId" IS NULL', [$defaultStatusId]);
            }
        });

        Schema::table('employee_leave', function (Blueprint $table) {
            if (Schema::hasColumn('employee_leave', 'approvalStatus')) {
                $table->dropColumn('approvalStatus');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_leave', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_leave', 'approvalStatus')) {
                $table->string('approvalStatus', 32)->default('pending')->after('multiplier');
            }
        });

        DB::table('employee_leave')
            ->join('leave_status', 'employee_leave.leaveStatusId', '=', 'leave_status.id')
            ->select('employee_leave.id', 'leave_status.code')
            ->orderBy('employee_leave.id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $legacy = match ($row->code) {
                        'SCHEDULED', 'TAKEN' => 'approved',
                        'REJECTED' => 'rejected',
                        'CANCELLED' => 'cancelled',
                        default => 'pending',
                    };

                    DB::table('employee_leave')->where('id', $row->id)->update([
                        'approvalStatus' => $legacy,
                    ]);
                }
            });

        Schema::table('employee_leave', function (Blueprint $table) {
            if (Schema::hasColumn('employee_leave', 'statusNote')) {
                $table->dropColumn('statusNote');
            }

            if (Schema::hasColumn('employee_leave', 'leaveStatusId')) {
                $table->dropForeign(['leaveStatusId']);
                $table->dropColumn('leaveStatusId');
            }
        });
    }
};
