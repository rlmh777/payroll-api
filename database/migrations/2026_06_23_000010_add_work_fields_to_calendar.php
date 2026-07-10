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
        Schema::table('calendar', function (Blueprint $table) {
            $table->unsignedBigInteger('worksiteId')->nullable()->after('departmentId');
            $table->foreign('worksiteId')->references('id')->on('worksite')->nullOnDelete();
            $table->boolean('includeLunchHour')->default(false)->after('endTime');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calendar', function (Blueprint $table) {
            $table->dropForeign(['worksiteId']);
            $table->dropColumn(['worksiteId', 'includeLunchHour']);
        });
    }
};
