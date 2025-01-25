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
            $table->string('legalName', 255);
            $table->string('alias', 255);
            $table->binary('socialSecurityNumber');
            $table->integer('taxIdentificationNumber');
            $table->string('logoPath', 255);
            $table->string('phoneNumber1', 24);
            $table->string('phoneNumber2', 24);
            $table->string('email', 255);
            $table->string('street', 255);
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
