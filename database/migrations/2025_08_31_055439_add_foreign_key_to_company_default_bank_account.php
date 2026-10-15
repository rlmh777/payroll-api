<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Schema::table('company', function (Blueprint $table) {
        //     $table->foreignUuid('defaultBankAccountId')->nullable()->constrained('bank_account')->onDelete('set null');
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::table('company', function (Blueprint $table) {
        //     $table->dropForeign(['defaultBankAccountId']);
        // });
    }
};
