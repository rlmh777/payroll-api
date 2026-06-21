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
        Schema::table('employee', function (Blueprint $table) {
            $table->foreignUuid('leadId')->nullable()->after('user_id')->constrained('employee')->nullOnDelete();
            $table->foreignUuid('supervisorId')->nullable()->after('leadId')->constrained('employee')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee', function (Blueprint $table) {
            $table->dropForeign(['leadId']);
            $table->dropColumn('leadId');
            $table->dropForeign(['supervisorId']);
            $table->dropColumn('supervisorId');
        });
    }
};
