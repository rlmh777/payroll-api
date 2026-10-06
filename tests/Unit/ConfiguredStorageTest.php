<?php

namespace Tests\Unit;

use App\Enums\StorageDriver;
use App\Models\StorageSetting;
use App\Support\ConfiguredStorage;
use App\Support\PublicFileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConfiguredStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        app(ConfiguredStorage::class)->forgetCachedDisk();
    }

    public function test_defaults_to_local_public_disk(): void
    {
        $settings = StorageSetting::current();

        $this->assertSame(StorageDriver::Local, $settings->driverEnum());
        $this->assertSame('uploads', $settings->container);
        $this->assertFalse($settings->hasSecret());
    }

    public function test_stores_and_deletes_on_local_disk(): void
    {
        $storage = app(ConfiguredStorage::class);
        $file = UploadedFile::fake()->create('letter.pdf', 12, 'application/pdf');

        $path = $storage->store($file, 'employee-documents', 'doc_test.pdf');

        $this->assertSame('employee-documents/doc_test.pdf', $path);
        $this->assertTrue($storage->exists($path));
        $this->assertStringContainsString('/storage/employee-documents/doc_test.pdf', $storage->url($path));

        $storage->delete($path);
        $this->assertFalse($storage->exists($path));
    }

    public function test_backup_disk_is_private_local_when_file_storage_is_local(): void
    {
        Storage::fake('local');
        $storage = app(ConfiguredStorage::class);
        $local = tempnam(sys_get_temp_dir(), 'dump_');
        file_put_contents($local, 'DUMP');

        $storage->putFromLocal('database-backups/test.dump', $local, $storage->backupDisk());

        $this->assertTrue(Storage::disk('local')->exists('database-backups/test.dump'));
        $this->assertFalse(Storage::disk('public')->exists('database-backups/test.dump'));

        $copied = $storage->copyToLocal('database-backups/test.dump', $storage->backupDisk());
        $this->assertSame('DUMP', file_get_contents($copied));
        @unlink($copied);
        @unlink($local);
    }

    public function test_public_file_upload_uses_configured_disk(): void
    {
        $file = UploadedFile::fake()->create('cert.pdf', 8, 'application/pdf');
        $request = Request::create('/employee-certifications', 'POST', [], [], [
            'attachmentFile' => $file,
        ]);

        $data = PublicFileUpload::apply($request, [], 'employee-certifications');

        $this->assertArrayHasKey('filePath', $data);
        $this->assertTrue(app(ConfiguredStorage::class)->exists($data['filePath']));
        $this->assertSame('cert.pdf', $data['fileName']);
    }

    public function test_connection_succeeds_for_local_disk(): void
    {
        $result = app(ConfiguredStorage::class)->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertSame('local', $result['driver']);
    }

    public function test_azure_connection_fails_without_credentials(): void
    {
        $settings = StorageSetting::current();
        $settings->driver = StorageDriver::Azure->value;
        $settings->container = 'uploads';
        $settings->account_name = null;
        $settings->account_key = null;

        $result = app(ConfiguredStorage::class)->testConnection($settings);

        $this->assertFalse($result['ok']);
        $this->assertSame('azure', $result['driver']);
        $this->assertStringContainsString('account', strtolower($result['message']));
    }

    public function test_azure_disk_uses_shared_key_credential(): void
    {
        $settings = StorageSetting::current();
        $settings->driver = StorageDriver::Azure->value;
        $settings->container = 'chaacreek';
        $settings->account_name = 'stexample';
        $settings->account_key = base64_encode('test-key');

        $storage = app(ConfiguredStorage::class);
        $method = new \ReflectionMethod(ConfiguredStorage::class, 'azureDisk');
        $disk = $method->invoke($storage, $settings);

        $this->assertInstanceOf(\Illuminate\Contracts\Filesystem\Filesystem::class, $disk);
    }

    public function test_account_key_is_hidden_on_the_model(): void
    {
        $settings = StorageSetting::current();
        $settings->update([
            'driver' => StorageDriver::Azure->value,
            'container' => 'uploads',
            'account_name' => 'stexample',
            'account_key' => 'super-secret-key',
        ]);

        $this->assertArrayNotHasKey('account_key', $settings->fresh()->toArray());
        $this->assertTrue($settings->fresh()->hasSecret());
    }

    public function test_configure_command_writes_azure_settings_from_env(): void
    {
        config([
            'tenancy.enabled' => false,
        ]);

        $this->artisan('storage:configure', ['--force' => true])
            ->expectsOutputToContain('leaving File Storage settings unchanged')
            ->assertSuccessful();

        $this->artisan('storage:configure', [
            '--force' => true,
            '--account-name' => 'stpayrollshrdprodi7i0ek',
            '--account-key' => 'test-key',
            '--container' => 'chaacreek',
        ])->assertSuccessful();

        $settings = StorageSetting::current()->fresh();
        $this->assertSame('azure', $settings->driver);
        $this->assertSame('chaacreek', $settings->container);
        $this->assertSame('stpayrollshrdprodi7i0ek', $settings->account_name);
        $this->assertTrue($settings->hasSecret());
        $this->assertArrayNotHasKey('account_key', $settings->toArray());
    }
}
