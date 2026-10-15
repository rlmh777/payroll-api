<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_type_policy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leaveTypeId')->unique()->constrained('leave_type')->cascadeOnDelete();
            $table->decimal('annualEntitlementDays', 8, 2)->default(0);
            $table->string('accrualMethod', 16)->default('UPFRONT');
            $table->boolean('isEnabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_type_policy');
    }
};
