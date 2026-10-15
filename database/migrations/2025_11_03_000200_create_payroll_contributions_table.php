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
        Schema::create('payroll_contributions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->uuid('account_id');
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->foreign('employee_id')
                ->references('id')->on('employee')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign('account_id')
                ->references('id')->on('accounts')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->index(['employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_contributions');
    }
};

