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
            $table->text('note');
            $table->foreignUuid('employeeId')->constrained('employee')->onDelete('cascade');
            $table->foreignId('loanTypeId')->constrained('loan_type')->onDelete('cascade');
            $table->decimal('loanAmount', total: 12, places: 2);
            $table->enum('interestType', ['Compound Interest', 'Simple Interest'])->default('Simple Interest');
            $table->decimal('annualInterestRate', total: 6, places: 2)->default(0.0);
            $table->integer('loanPeriods');
            $table->decimal('optionalExtraPayment', total: 12, places: 2);
            $table->foreignUuid('chartOfAccountId')->constrained('chart_of_account')->onDelete('cascade');
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
