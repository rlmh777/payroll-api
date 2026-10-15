<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_type', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_type', 'uncertifiedDaysAllowed')) {
                $table->decimal('uncertifiedDaysAllowed', 8, 2)->default(0)->after('requiresCertification');
            }
        });

        if (Schema::hasColumn('leave_type', 'uncertifiedDaysAllowed')) {
            DB::table('leave_type')
                ->where('code', 'SICK')
                ->where('requiresCertification', true)
                ->update(['uncertifiedDaysAllowed' => 2]);
        }
    }

    public function down(): void
    {
        Schema::table('leave_type', function (Blueprint $table) {
            if (Schema::hasColumn('leave_type', 'uncertifiedDaysAllowed')) {
                $table->dropColumn('uncertifiedDaysAllowed');
            }
        });
    }
};
