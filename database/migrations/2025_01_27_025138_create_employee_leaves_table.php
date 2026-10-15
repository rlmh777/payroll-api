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
        // Check if track_employee_leave table exists and rename it
        if (Schema::hasTable('track_employee_leave')) {
            Schema::rename('track_employee_leave', 'employee_leave');
        } else {
            // Create new table if it doesn't exist
            if (!Schema::hasTable('employee_leave')) {
                Schema::create('employee_leave', function (Blueprint $table) {
                    $table->uuid('id')->primary();
                    $table->foreignUuid('employeeId')->constrained('employee')->onDelete('cascade');
                    $table->foreignId('leaveTypeId')->constrained('leave_type')->onDelete('cascade');
                    $table->date('startDate');
                    $table->date('endDate');
                    $table->time('fromTime');
                    $table->time('toTime');
                    $table->enum('duration', ['Full Day', 'All Days', 'Morning', 'Afternoon', 'Custom'])->default('Full Day');
                    $table->float('totalDays');
                    $table->text('notes')->nullable();
                    $table->float('multiplier')->default(1);
                    $table->timestamps();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rename back to track_employee_leave if it was renamed
        if (Schema::hasTable('employee_leave') && !Schema::hasTable('track_employee_leave')) {
            Schema::rename('employee_leave', 'track_employee_leave');
        } else {
            Schema::dropIfExists('employee_leave');
        }
    }
};
