<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_security_contribution_rule', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('priority')->default(10);
            $table->enum('employee_contribution_method', ['NONE', 'TIER_TABLE', 'FIXED_WEEKLY', 'RATE'])->default('TIER_TABLE');
            $table->enum('employer_contribution_method', ['NONE', 'TIER_TABLE', 'FIXED_WEEKLY', 'RATE'])->default('TIER_TABLE');
            $table->decimal('employee_fixed_weekly_amount', 10, 2)->nullable();
            $table->decimal('employer_fixed_weekly_amount', 10, 2)->nullable();
            $table->decimal('employee_rate', 5, 2)->nullable();
            $table->decimal('employer_rate', 5, 2)->nullable();
            $table->boolean('skip_tier_lookup')->default(false);
            $table->json('conditions');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->enum('state', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('employee_ss_benefit_status', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('employeeId');
            $table->foreign('employeeId')->references('id')->on('employee')->cascadeOnDelete();
            $table->boolean('is_receiving_benefit')->default(false);
            $table->string('benefit_type', 64)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->date('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employeeId', 'effective_from']);
        });

        Schema::table('payroll', function (Blueprint $table) {
            $table->uuid('applied_ss_rule_id')->nullable()->after('socialSecurityCalculationModeId');
            $table->uuid('applied_ss_tier_id')->nullable()->after('applied_ss_rule_id');
            $table->json('ss_calculation_detail')->nullable()->after('applied_ss_tier_id');

            $table->foreign('applied_ss_rule_id')
                ->references('id')
                ->on('social_security_contribution_rule')
                ->nullOnDelete();
            $table->foreign('applied_ss_tier_id')
                ->references('id')
                ->on('social_security')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll', function (Blueprint $table) {
            $table->dropForeign(['applied_ss_rule_id']);
            $table->dropForeign(['applied_ss_tier_id']);
            $table->dropColumn(['applied_ss_rule_id', 'applied_ss_tier_id', 'ss_calculation_detail']);
        });

        Schema::dropIfExists('employee_ss_benefit_status');
        Schema::dropIfExists('social_security_contribution_rule');
    }
};
