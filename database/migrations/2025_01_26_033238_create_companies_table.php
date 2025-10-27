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
        Schema::create('company', function (Blueprint $table) {
            $table->id('id')->primary();
            $table->string('legalName');
            $table->string('alias');
            $table->binary('socialSecurityNumber');
            $table->integer('taxIdentificationNumber');
            $table->string('logoPath', 255);
            $table->string('phoneNumber1');
            $table->string('phoneNumber2');
            $table->string('email');
            $table->string('street', 255);
            $table->foreignUuid('localityId')->constrained('locality')->onDelete('cascade');
            $table->string('logo', 255);
            $table->foreignUuid('wagesPayableAccountId')->constrained('chart_of_accounts')->onDelete('cascade');
            $table->foreignUuid('defaultBankAccountId')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company');
    }
};
