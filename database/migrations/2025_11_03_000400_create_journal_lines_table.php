<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('journal_id');
            $table->uuid('account_id');
            $table->string('description', 191)->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->timestamps();

            $table->foreign('journal_id')
                ->references('id')->on('journal_entries')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->foreign('account_id')
                ->references('id')->on('accounts')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->index(['journal_id', 'account_id']);
        });

        // Add check constraints: (debit = 0 OR credit = 0) AND (debit >= 0 AND credit >= 0)
        // Use DB statements for portability across Laravel versions
        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_one_side_zero CHECK ((debit = 0 OR credit = 0))");
        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_non_negative CHECK ((debit >= 0 AND credit >= 0))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};

