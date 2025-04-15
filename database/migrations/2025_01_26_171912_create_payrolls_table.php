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
        Schema::create('payroll', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')->constrained('employee')->onDelete('cascade');
            $table->date('date')->useCurrent();
            $table->decimal('totalRegularHours', total: 6, places: 2);
            $table->decimal('totalOvertimeHours', total: 6, places: 2);
            $table->decimal('employeeSocialSecurityAmount', total: 8, places: 2);
            $table->decimal('employerSocialSecurityAmount', total: 8, places: 2);
            $table->decimal('incomeTaxAmount', total: 8, places: 2);
            $table->decimal('grossSalary', total: 6, places: 2);
            $table->decimal('netSalary', total: 6, places: 2);
            $table->decimal('totalDeductions', total: 6, places: 2);
            $table->decimal('totalAllowances', total: 6, places: 2);
            $table->foreignId('taxCalculationModeId')->constrained('calculation_mode')->onDelete('cascade');
            $table->foreignId('socialSecurityCalculationModeId')->constrained('calculation_mode')->onDelete('cascade');
            $table->foreignId('paymentMethodId')->constrained('payment_method')->onDelete('cascade');
            $table->text('note');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll');
    }
};
