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
        Schema::create('bank', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('code', 24);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign key constraints that reference the bank table
        // Check and drop foreign keys from vendor table
        if (Schema::hasTable('vendor')) {
            Schema::table('vendor', function (Blueprint $table) {
                $table->dropForeign(['bankId']);
            });
        }
        
        // Check and drop foreign keys from company_bank_accounts table
        if (Schema::hasTable('company_bank_accounts')) {
            Schema::table('company_bank_accounts', function (Blueprint $table) {
                $table->dropForeign(['bankId']);
            });
        }
        
        // Check and drop foreign keys from employee_banks table
        if (Schema::hasTable('employee_banks')) {
            Schema::table('employee_banks', function (Blueprint $table) {
                $table->dropForeign(['bankId']);
            });
        }
        
        Schema::dropIfExists('bank');
    }
};
