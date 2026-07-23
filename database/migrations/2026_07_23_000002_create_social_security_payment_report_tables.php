<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_security_payment_reports')) {
            Schema::create('social_security_payment_reports', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->timestamp('calculated_at')->nullable();
                $table->uuid('calculated_by')->nullable();
                $table->timestamps();

                $table->unique(['year', 'month']);
            });
        }

        if (! Schema::hasTable('social_security_payment_report_lines')) {
            Schema::create('social_security_payment_report_lines', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('report_id');
                $table->uuid('employee_id')->nullable();
                $table->uuid('payroll_id')->nullable();
                $table->uuid('payroll_run_id')->nullable();
                $table->string('employee_social_security_number', 64)->nullable();
                $table->string('company_social_security_number', 64)->nullable();
                $table->unsignedSmallInteger('year');
                $table->string('month_name', 32);
                $table->unsignedTinyInteger('calendar_week');
                $table->date('week_monday_date');
                $table->decimal('weekly_gross_pay', 12, 2)->default(0);
                $table->decimal('social_security_amount', 12, 2)->default(0);
                $table->string('electronic_employer_number', 64)->nullable();
                $table->date('date_hired')->nullable();
                $table->string('first_name', 255)->nullable();
                $table->string('last_name', 255)->nullable();
                $table->string('record_code', 8)->default('P');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('report_id')
                    ->references('id')
                    ->on('social_security_payment_reports')
                    ->onDelete('cascade');

                $table->index(['report_id', 'sort_order']);
                $table->index(['year', 'month_name', 'calendar_week']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('social_security_payment_report_lines');
        Schema::dropIfExists('social_security_payment_reports');
    }
};
