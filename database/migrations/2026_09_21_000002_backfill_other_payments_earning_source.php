<?php

use App\Models\PayrollEarningCode;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $fallback = PayrollEarningCode::query()
            ->where('source', PayrollEarningCode::SOURCE_ALLOWANCE_FALLBACK)
            ->exists();

        if ($fallback) {
            return;
        }

        PayrollEarningCode::query()
            ->whereIn('code', ['ALLOWANCE', 'OTHER_PAYMENTS'])
            ->whereNull('source')
            ->limit(1)
            ->update([
                'source' => PayrollEarningCode::SOURCE_ALLOWANCE_FALLBACK,
                'post_to_department_account' => false,
            ]);
    }

    public function down(): void
    {
        // Source assignment is data, not schema.
    }
};
