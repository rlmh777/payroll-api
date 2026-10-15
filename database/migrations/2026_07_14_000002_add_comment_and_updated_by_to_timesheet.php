<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            $table->text('comment')->nullable()->after('remarks');
            $table->uuid('updatedBy')->nullable()->after('comment');
            $table->foreign('updatedBy')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            $table->dropForeign(['updatedBy']);
            $table->dropColumn(['comment', 'updatedBy']);
        });
    }
};
