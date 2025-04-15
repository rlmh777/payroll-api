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
        Schema::create('historical_employee_deduction', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')->constrained('employee')->onDelete('cascade');
            $table->foreignUuid('paymentToId')->constrained('vendor')->onDelete('cascade');
            $table->decimal('amount', total: 6, places: 2);
            $table->text('note');
            $table->foreignUuid('payrollId')->constrained('payroll')->onDelete('cascade');
            $table->foreignUuid('chartOfAccountId')->constrained('chart_of_account')->onDelete('cascade');
            $table->foreignId('deductionTypeId')->constrained('deduction_type')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('historical_employee_deduction');
    }
};
