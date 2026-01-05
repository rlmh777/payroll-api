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
        if (!Schema::hasTable('account_types')) {
            Schema::create('account_types', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('normal_balance')->nullable();
                $table->enum('statement', ['Balance Sheet', 'Income Statement'])->nullable();
                $table->timestamps();
            });
        } else {
            // Table exists, but check if columns need to be added
            Schema::table('account_types', function (Blueprint $table) {
                if (!Schema::hasColumn('account_types', 'normal_balance')) {
                    $table->string('normal_balance')->nullable()->after('name');
                }
                if (!Schema::hasColumn('account_types', 'statement')) {
                    $table->enum('statement', ['Balance Sheet', 'Income Statement'])->nullable()->after('normal_balance');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_types');
    }
};
