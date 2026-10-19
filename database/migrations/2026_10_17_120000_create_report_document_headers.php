<?php

use App\Models\Menu;
use App\Modules\Payroll\Services\ReportDocumentHeaderDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_document_headers')) {
            Schema::create('report_document_headers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('kind');
                $table->boolean('is_system')->default(false);
                $table->json('canvas');
                $table->timestamps();
            });
        }

        $now = now();
        foreach (ReportDocumentHeaderDefaults::all() as $header) {
            $exists = \Illuminate\Support\Facades\DB::table('report_document_headers')
                ->where('kind', $header['kind'])
                ->where('is_system', true)
                ->exists();
            if ($exists) {
                continue;
            }

            \Illuminate\Support\Facades\DB::table('report_document_headers')->insert([
                'id' => (string) Str::uuid(),
                'name' => $header['name'],
                'kind' => $header['kind'],
                'is_system' => true,
                'canvas' => json_encode($header['canvas']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! Schema::hasTable('menus')) {
            return;
        }

        $parentId = Menu::query()->where('system_key', 'payroll.settings')->value('id');
        if (! $parentId) {
            return;
        }

        $existing = Menu::query()->where('system_key', 'payroll.settings.document-headers')->first();
        if ($existing) {
            return;
        }

        Menu::query()->create([
            'parent_id' => $parentId,
            'title' => 'Document Headers',
            'route' => '/payroll/settings/document-headers',
            'icon' => 'fas fa-heading',
            'permission' => 'view-settings',
            'order' => 10,
            'type' => 'submenu',
            'is_active' => true,
            'module_code' => 'payroll',
            'source' => 'system',
            'system_key' => 'payroll.settings.document-headers',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('report_document_headers');

        if (Schema::hasTable('menus')) {
            Menu::query()->where('system_key', 'payroll.settings.document-headers')->delete();
        }
    }
};
