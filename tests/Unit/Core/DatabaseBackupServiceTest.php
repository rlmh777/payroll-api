<?php

namespace Tests\Unit\Core;

use App\Models\DatabaseBackup;
use App\Modules\Core\Services\DatabaseBackupService;
use App\Support\ConfiguredStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        app(ConfiguredStorage::class)->forgetCachedDisk();
    }

    public function test_settings_use_admin_file_storage_location(): void
    {
        $settings = app(DatabaseBackupService::class)->settings();

        $this->assertSame('local', $settings['storageDriver']);
        $this->assertNull($settings['storageContainer']);
        $this->assertSame('database-backups', $settings['storageDirectory']);
    }

    public function test_delete_removes_backup_from_configured_storage(): void
    {
        $storage = app(ConfiguredStorage::class);
        $path = 'database-backups/payroll_test.dump';
        $storage->backupDisk()->put($path, 'DUMP');

        $backup = DatabaseBackup::query()->create([
            'filename' => 'payroll_test.dump',
            'disk' => 'local',
            'path' => $path,
            'type' => 'manual',
            'size_bytes' => 4,
            'status' => 'completed',
        ]);

        app(DatabaseBackupService::class)->delete($backup);

        $this->assertFalse($storage->backupDisk()->exists($path));
        $this->assertDatabaseMissing('database_backups', ['id' => $backup->id]);
    }
}
