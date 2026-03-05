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
        Schema::create('timesheet', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')->constrained('employee')->onDelete('cascade');
            $table->foreignUuid('calendar_group_id')->nullable()->constrained('calendar_groups')->nullOnDelete();
            $table->date('date');
            $table->decimal('hoursWorked', total: 6, places: 2)->default(0);
            $table->string('approvalStatus', 32)->default('pending');
            $table->timestamps();

            $table->index(['employeeId', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timesheet');
    }
};
