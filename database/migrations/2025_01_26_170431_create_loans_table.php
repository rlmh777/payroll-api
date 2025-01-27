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
        Schema::create('loan', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 64);
            $table->foreignId('employeeId')->constrained('employee')->onDelete('cascade');
            $table->foreignId('loanTypeId')->constrained('loan_type')->onDelete('cascade');
            $table->decimal('loanAmount', total: 12, places: 2);
            $table->decimal('annualInterestRate', total: 12, places: 2);
            $table->integer('loanPeriods');
            $table->decimal('optionalExtraPayment', total: 12, places: 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan');
    }
};
