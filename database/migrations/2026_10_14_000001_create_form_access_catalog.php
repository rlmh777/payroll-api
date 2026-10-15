<?php

use App\Modules\Core\Services\FormAccessCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('form_access_forms')) {
            Schema::create('form_access_forms', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('key')->unique();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('form_access_tabs')) {
            Schema::create('form_access_tabs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('form_id');
                $table->string('key');
                $table->string('label');
                $table->string('group', 32)->default('details');
                $table->unsignedSmallInteger('sort')->default(0);
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('form_id')->references('id')->on('form_access_forms')->cascadeOnDelete();
                $table->unique(['form_id', 'key']);
            });
        }

        if (! Schema::hasTable('form_access_fields')) {
            Schema::create('form_access_fields', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('form_id');
                $table->string('tab_key');
                $table->string('key');
                $table->string('label');
                $table->string('input_type', 32)->default('text');
                $table->boolean('is_required')->default(false);
                $table->json('options')->nullable();
                $table->string('help_text')->nullable();
                $table->unsignedSmallInteger('sort')->default(0);
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('form_id')->references('id')->on('form_access_forms')->cascadeOnDelete();
                $table->unique(['form_id', 'key']);
                $table->index(['form_id', 'tab_key']);
            });
        } elseif (! Schema::hasColumn('form_access_fields', 'input_type')) {
            Schema::table('form_access_fields', function (Blueprint $table) {
                $table->string('input_type', 32)->default('text')->after('label');
                $table->boolean('is_required')->default(false)->after('input_type');
                $table->json('options')->nullable()->after('is_required');
                $table->string('help_text')->nullable()->after('options');
            });
        }

        app(FormAccessCatalog::class)->syncEmployee();
    }

    public function down(): void
    {
        Schema::dropIfExists('form_access_fields');
        Schema::dropIfExists('form_access_tabs');
        Schema::dropIfExists('form_access_forms');
    }
};
