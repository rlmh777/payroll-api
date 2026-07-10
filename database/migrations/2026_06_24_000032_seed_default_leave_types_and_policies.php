<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultLeaveTypes(): array
    {
        return [
            [
                'code' => 'VACATION',
                'name' => 'Vacation',
                'isPaid' => true,
                'affectsBalance' => true,
                'requiresCertification' => false,
                'sortOrder' => 10,
                'annualEntitlementDays' => 20,
                'accrualMethod' => 'MONTHLY',
            ],
            [
                'code' => 'SICK',
                'name' => 'Sick',
                'isPaid' => true,
                'affectsBalance' => true,
                'requiresCertification' => true,
                'sortOrder' => 20,
                'annualEntitlementDays' => 14,
                'accrualMethod' => 'UPFRONT',
            ],
            [
                'code' => 'SICK_UNCERTIFIED',
                'name' => 'Sick (uncertified)',
                'isPaid' => true,
                'affectsBalance' => true,
                'requiresCertification' => false,
                'sortOrder' => 30,
                'annualEntitlementDays' => 2,
                'accrualMethod' => 'UPFRONT',
            ],
            [
                'code' => 'PTO',
                'name' => 'Paid time off',
                'isPaid' => true,
                'affectsBalance' => true,
                'requiresCertification' => false,
                'sortOrder' => 40,
                'annualEntitlementDays' => 5,
                'accrualMethod' => 'UPFRONT',
            ],
            [
                'code' => 'PATERNITY',
                'name' => 'Paternity',
                'isPaid' => true,
                'affectsBalance' => true,
                'requiresCertification' => false,
                'sortOrder' => 50,
                'annualEntitlementDays' => 5,
                'accrualMethod' => 'UPFRONT',
            ],
            [
                'code' => 'PROFESSIONAL',
                'name' => 'Professional',
                'isPaid' => true,
                'affectsBalance' => true,
                'requiresCertification' => false,
                'sortOrder' => 60,
                'annualEntitlementDays' => 5,
                'accrualMethod' => 'UPFRONT',
            ],
            [
                'code' => 'WITHOUT_PAY',
                'name' => 'Without pay',
                'isPaid' => false,
                'affectsBalance' => false,
                'requiresCertification' => false,
                'sortOrder' => 80,
                'annualEntitlementDays' => 0,
                'accrualMethod' => 'NONE',
            ],
        ];
    }

    public function up(): void
    {
        if (!Schema::hasTable('leave_type')) {
            return;
        }

        $now = now();

        foreach ($this->defaultLeaveTypes() as $index => $type) {
            $existing = DB::table('leave_type')->where('code', $type['code'])->first();

            if ($existing) {
                DB::table('leave_type')->where('id', $existing->id)->update([
                    'name' => $type['name'],
                    'isPaid' => $type['isPaid'],
                    'affectsBalance' => $type['affectsBalance'],
                    'requiresCertification' => $type['requiresCertification'],
                    'isActive' => true,
                    'sortOrder' => $type['sortOrder'],
                    'updated_at' => $now,
                ]);
                $leaveTypeId = $existing->id;
            } else {
                $leaveTypeId = DB::table('leave_type')->insertGetId([
                    'name' => $type['name'],
                    'code' => $type['code'],
                    'isPaid' => $type['isPaid'],
                    'affectsBalance' => $type['affectsBalance'],
                    'requiresCertification' => $type['requiresCertification'],
                    'isActive' => true,
                    'sortOrder' => $type['sortOrder'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (!Schema::hasTable('leave_type_policy')) {
                continue;
            }

            $policyExists = DB::table('leave_type_policy')
                ->where('leaveTypeId', $leaveTypeId)
                ->exists();

            if ($policyExists) {
                DB::table('leave_type_policy')
                    ->where('leaveTypeId', $leaveTypeId)
                    ->update([
                        'annualEntitlementDays' => $type['annualEntitlementDays'],
                        'accrualMethod' => $type['accrualMethod'],
                        'isEnabled' => true,
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('leave_type_policy')->insert([
                    'leaveTypeId' => $leaveTypeId,
                    'annualEntitlementDays' => $type['annualEntitlementDays'],
                    'accrualMethod' => $type['accrualMethod'],
                    'isEnabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::table('leave_type')
            ->whereNull('code')
            ->orderBy('id')
            ->get()
            ->each(function ($row) use ($now) {
                $code = strtoupper(preg_replace('/[^A-Z0-9]+/', '_', $row->name) ?? 'LEGACY');
                $code = trim($code, '_') ?: 'LEGACY';
                $suffix = 1;
                $candidate = $code;
                while (DB::table('leave_type')->where('code', $candidate)->where('id', '!=', $row->id)->exists()) {
                    $candidate = $code . '_' . $suffix;
                    $suffix++;
                }

                DB::table('leave_type')->where('id', $row->id)->update([
                    'code' => $candidate,
                    'isPaid' => $row->isPaid ?? true,
                    'affectsBalance' => $row->affectsBalance ?? true,
                    'requiresCertification' => $row->requiresCertification ?? false,
                    'isActive' => $row->isActive ?? true,
                    'sortOrder' => $row->sortOrder ?? 100,
                    'updated_at' => $now,
                ]);

                if (Schema::hasTable('leave_type_policy')) {
                    $hasPolicy = DB::table('leave_type_policy')->where('leaveTypeId', $row->id)->exists();
                    if (!$hasPolicy) {
                        DB::table('leave_type_policy')->insert([
                            'leaveTypeId' => $row->id,
                            'annualEntitlementDays' => 0,
                            'accrualMethod' => 'NONE',
                            'isEnabled' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data migration — no rollback.
    }
};
