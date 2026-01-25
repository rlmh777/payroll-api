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
        Schema::create('social_security', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->decimal('weeklyEarningsStartRange', 10, 2);
            $table->decimal('weeklyEarningsEndRange', 10, 2);
            $table->decimal('weeklyInsurableEarnings', 10, 2);
            $table->decimal('weeklyEmployeeContributions', 10, 2);
            $table->decimal('weeklyEmployerContributions', 10, 2);
            $table->decimal('weekyEmployeeContributionsRate', 5, 2);
            $table->decimal('weeklyEmployerContributionsRate', 5, 2);
            $table->decimal('maxWeeklyShortTermBenefit', 10, 2);
            $table->decimal('maxWeeklyPensions', 10, 2);
            $table->decimal('maxYearlyPension', 10, 2);
            $table->enum('state', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_security');
    }
};

