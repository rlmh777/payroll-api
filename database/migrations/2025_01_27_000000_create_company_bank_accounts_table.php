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
        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('companyId')->constrained('company')->onDelete('cascade');
            $table->foreignUuid('bankId')->constrained('bank')->onDelete('cascade');
            $table->string('accountNumber');
            $table->foreignId('accountTypeId')->constrained('bank_account_type')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_bank_accounts');
    }
}; 