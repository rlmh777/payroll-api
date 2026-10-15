<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('leave_type')) {
            return;
        }

        $vacationId = $this->resolveTargetId('VACATION', 'Vacation');
        $sickId = $this->resolveTargetId('SICK', 'Sick');

        $this->reassignAndRemove($this->findIdsByNames(['Annual Leave']), $vacationId);
        $this->reassignAndRemove($this->findIdsByNames(['Sick Leave']), $sickId);
        $this->reassignAndRemove($this->findIdsByCode('STUDY'), null);

        // Deactivate legacy rows that were auto-coded from old names but duplicate canonical types.
        $this->deactivateLegacyDuplicates($sickId, ['SICK_LEAVE', 'SICK_LEAVE_1']);
        $this->deactivateLegacyDuplicates($vacationId, ['ANNUAL_LEAVE', 'ANNUAL_LEAVE_1']);

        if ($sickId) {
            DB::table('leave_type')->where('id', $sickId)->update([
                'name' => 'Sick',
                'code' => 'SICK',
                'isActive' => true,
                'updated_at' => now(),
            ]);
        }

        if ($vacationId) {
            DB::table('leave_type')->where('id', $vacationId)->update([
                'name' => 'Vacation',
                'code' => 'VACATION',
                'isActive' => true,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Data migration — no rollback.
    }

    private function resolveTargetId(string $code, string $name): ?int
    {
        $id = DB::table('leave_type')->where('code', $code)->value('id');

        if ($id) {
            return (int) $id;
        }

        $id = DB::table('leave_type')->where('name', $name)->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * @param array<int, int> $sourceIds
     */
    private function reassignAndRemove(array $sourceIds, ?int $targetId): void
    {
        foreach ($sourceIds as $sourceId) {
            if (Schema::hasTable('employee_leave')) {
                if ($targetId && $sourceId !== $targetId) {
                    DB::table('employee_leave')
                        ->where('leaveTypeId', $sourceId)
                        ->update(['leaveTypeId' => $targetId]);
                } else {
                    DB::table('employee_leave')->where('leaveTypeId', $sourceId)->delete();
                }
            }

            if (Schema::hasTable('employment_leave_entitlement')) {
                DB::table('employment_leave_entitlement')->where('leaveTypeId', $sourceId)->delete();
            }

            if (Schema::hasTable('leave_type_policy')) {
                DB::table('leave_type_policy')->where('leaveTypeId', $sourceId)->delete();
            }

            DB::table('leave_type')->where('id', $sourceId)->delete();
        }
    }

    /**
     * @param array<int, string> $names
     * @return array<int, int>
     */
    private function findIdsByNames(array $names): array
    {
        return DB::table('leave_type')
            ->whereIn('name', $names)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function findIdsByCode(string $code): array
    {
        return DB::table('leave_type')
            ->where('code', $code)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param array<int, string> $codes
     */
    private function deactivateLegacyDuplicates(?int $canonicalId, array $codes): void
    {
        if (!$canonicalId) {
            return;
        }

        DB::table('leave_type')
            ->whereIn('code', $codes)
            ->where('id', '!=', $canonicalId)
            ->update(['isActive' => false, 'updated_at' => now()]);
    }
};
