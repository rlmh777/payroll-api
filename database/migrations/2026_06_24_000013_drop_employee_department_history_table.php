<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('employee_department_history');
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_department_history')) {
            return;
        }

        Schema::create('employee_department_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')->constrained('employee')->onDelete('cascade');
            $table->foreignId('departmentId')->constrained('department')->onDelete('cascade');
            $table->foreignId('previousDepartmentId')->nullable()->constrained('department')->nullOnDelete();
            $table->date('startDate');
            $table->date('endDate')->nullable();
            $table->boolean('isCurrent')->default(true);
            $table->string('transferReason', 255)->nullable();
            $table->string('approvalStatus', 32)->default('pending');
            $table->date('approvalDate')->nullable();
            $table->foreignUuid('approverId')->nullable()->constrained('employee')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employeeId', 'departmentId']);
        });
    }
};
