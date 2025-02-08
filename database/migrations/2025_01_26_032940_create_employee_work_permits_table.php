<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ParagonIE\CipherSweet\BlindIndex;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_work_permit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')->nullable()->constrained('employee')->onDelete('cascade');
            $table->string('workPermitNumber');
            $table->date('issued');
            $table->date('expires');
            $table->string('socialSecurityNumber');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_work_permit');
    }

    
};
