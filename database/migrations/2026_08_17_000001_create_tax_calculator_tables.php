<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_calculator_rates')) {
            Schema::create('tax_calculator_rates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('category', 32);
                $table->string('code', 64)->unique();
                $table->string('name', 128);
                $table->decimal('rate', 18, 8)->default(0);
                $table->string('iris_line', 32)->nullable();
                $table->boolean('applies_to_accounts')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tax_calculator_accounts')) {
            Schema::create('tax_calculator_accounts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('account_id')->unique();
                $table->string('business_tax_code', 64)->nullable();
                $table->string('gst_code', 64)->nullable();
                $table->boolean('include_btb')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('account_id')
                    ->references('id')
                    ->on('accounts')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('tax_calculator_runs')) {
            Schema::create('tax_calculator_runs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->decimal('gst_value_entered', 18, 6)->default(0);
                $table->decimal('partial_exemptions_total', 18, 6)->default(0);
                $table->decimal('line_220', 18, 6)->nullable();
                $table->decimal('net_of_2251', 18, 6)->default(0);
                $table->json('rates_snapshot')->nullable();
                $table->json('results')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();

                $table->unique(['year', 'month']);
            });
        }

        if (! Schema::hasTable('tax_calculator_run_lines')) {
            Schema::create('tax_calculator_run_lines', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tax_calculator_run_id');
                $table->uuid('account_id')->nullable();
                $table->string('account_code', 64)->nullable();
                $table->string('account_name', 255);
                $table->decimal('amount', 18, 6)->default(0);
                $table->string('business_tax_code', 64)->nullable();
                $table->string('gst_code', 64)->nullable();
                $table->boolean('include_btb')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('tax_calculator_run_id')
                    ->references('id')
                    ->on('tax_calculator_runs')
                    ->cascadeOnDelete();

                $table->foreign('account_id')
                    ->references('id')
                    ->on('accounts')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_calculator_run_lines');
        Schema::dropIfExists('tax_calculator_runs');
        Schema::dropIfExists('tax_calculator_accounts');
        Schema::dropIfExists('tax_calculator_rates');
    }
};
