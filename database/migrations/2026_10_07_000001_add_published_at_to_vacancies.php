<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('sort_order');
        });

        DB::table('vacancies')
            ->whereNull('published_at')
            ->whereIn('vacancy_stage_id', function ($query) {
                $query->select('id')->from('vacancy_stages')->where('lists_public', true);
            })
            ->update(['published_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('vacancies', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
