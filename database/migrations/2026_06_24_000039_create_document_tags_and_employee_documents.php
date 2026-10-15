<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('document_tag')) {
            Schema::create('document_tag', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('parentId')->nullable();
                $table->string('name');
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('sortOrder')->default(0);
                $table->boolean('isActive')->default(true);
                $table->timestamps();
            });

            Schema::table('document_tag', function (Blueprint $table) {
                $table->foreign('parentId')
                    ->references('id')
                    ->on('document_tag')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('employee_document')) {
            Schema::create('employee_document', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('employeeId')->constrained('employee')->cascadeOnDelete();
                $table->uuid('documentTagId')->nullable();
                $table->string('name');
                $table->text('description')->nullable();
                $table->timestamps();

                $table->index('employeeId');
                $table->index('documentTagId');
            });

            Schema::table('employee_document', function (Blueprint $table) {
                $table->foreign('documentTagId')
                    ->references('id')
                    ->on('document_tag')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('menus')) {
            return;
        }

        $exists = DB::table('menus')->where('route', '/settings/document-tags')->exists();
        if ($exists) {
            return;
        }

        $generalMenuId = DB::table('menus')
            ->where('route', '/settings')
            ->where('type', 'submenu')
            ->where('title', 'General')
            ->value('id');

        if (!$generalMenuId) {
            return;
        }

        $maxOrder = (int) DB::table('menus')
            ->where('parent_id', $generalMenuId)
            ->max('order');

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $generalMenuId,
            'title' => 'Document Tags',
            'route' => '/settings/document-tags',
            'icon' => 'label',
            'permission' => 'view-document-tags',
            'order' => $maxOrder + 1,
            'type' => 'submenu',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')->where('route', '/settings/document-tags')->delete();
        }

        Schema::dropIfExists('employee_document');
        Schema::dropIfExists('document_tag');
    }
};
