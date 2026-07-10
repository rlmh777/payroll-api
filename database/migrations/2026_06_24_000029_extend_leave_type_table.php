<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_type', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_type', 'code')) {
                $table->string('code', 32)->nullable()->unique()->after('name');
            }
            if (!Schema::hasColumn('leave_type', 'isPaid')) {
                $table->boolean('isPaid')->default(true)->after('code');
            }
            if (!Schema::hasColumn('leave_type', 'affectsBalance')) {
                $table->boolean('affectsBalance')->default(true)->after('isPaid');
            }
            if (!Schema::hasColumn('leave_type', 'requiresCertification')) {
                $table->boolean('requiresCertification')->default(false)->after('affectsBalance');
            }
            if (!Schema::hasColumn('leave_type', 'isActive')) {
                $table->boolean('isActive')->default(true)->after('requiresCertification');
            }
            if (!Schema::hasColumn('leave_type', 'sortOrder')) {
                $table->unsignedSmallInteger('sortOrder')->default(0)->after('isActive');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_type', function (Blueprint $table) {
            foreach (['code', 'isPaid', 'affectsBalance', 'requiresCertification', 'isActive', 'sortOrder'] as $column) {
                if (Schema::hasColumn('leave_type', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
