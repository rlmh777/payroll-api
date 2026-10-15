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
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('entry_date');
            $table->text('memo');
            $table->string('source', 191);
            $table->uuid('payroll_run_id');
            $table->timestamps();

            $table->foreign('payroll_run_id')
                ->references('id')->on('payroll_runs')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->index(['entry_date', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};

