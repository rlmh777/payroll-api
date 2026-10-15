<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_leave_entitlement', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employmentDetailId')->constrained('employment_detail')->cascadeOnDelete();
            $table->foreignId('leaveTypeId')->constrained('leave_type')->cascadeOnDelete();
            $table->decimal('annualEntitlementDays', 8, 2)->nullable();
            $table->string('accrualMethod', 16)->nullable();
            $table->timestamps();

            $table->unique(['employmentDetailId', 'leaveTypeId']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_leave_entitlement');
    }
};
