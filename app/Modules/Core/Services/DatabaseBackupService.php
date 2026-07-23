<?php

namespace App\Modules\Core\Services;

use App\Models\DatabaseBackup;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupService
{
    public function settings(): array
    {
        $scheduleTimes = config('database-backup.schedule_times', []);
        $retentionDays = (int) config('database-backup.retention_days', 30);
        $maxBackups = (int) config('database-backup.max_backups', 120);

        return [
            'enabled' => (bool) config('database-backup.enabled', true),
            'retentionDays' => $retentionDays,
            'maxBackups' => $maxBackups,
            'scheduleTimes' => $scheduleTimes,
            'timezone' => config('app.timezone'),
            'backupCount' => DatabaseBackup::query()->where('status', 'completed')->count(),
            'totalSizeBytes' => (int) DatabaseBackup::query()->where('status', 'completed')->sum('size_bytes'),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, DatabaseBackup>
     */
    public function list()
    {
        return DatabaseBackup::query()
            ->with(['creator:id,name,email'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function create(string $type = 'manual', ?string $label = null, ?User $user = null): DatabaseBackup
    {
        if (! config('database-backup.enabled', true)) {
            throw new RuntimeException('Database backups are disabled.');
        }

        $this->assertToolsAvailable();

        $disk = (string) config('database-backup.disk', 'local');
        $directory = trim((string) config('database-backup.directory', 'database-backups'), '/');
        $timestamp = now()->format('Ymd_His');
        $filename = "payroll_{$timestamp}.dump";
        $relativePath = "{$directory}/{$filename}";

        $backup = DatabaseBackup::create([
            'filename' => $filename,
            'disk' => $disk,
            'path' => $relativePath,
            'type' => $type,
            'label' => $label,
            'size_bytes' => 0,
            'status' => 'pending',
            'created_by' => $user?->id,
        ]);

        $absolutePath = Storage::disk($disk)->path($relativePath);
        Storage::disk($disk)->makeDirectory($directory);

        try {
            $this->runPgDump($absolutePath);

            if (! is_file($absolutePath) || filesize($absolutePath) === 0) {
                throw new RuntimeException('Backup file was not created or is empty.');
            }

            $backup->update([
                'status' => 'completed',
                'size_bytes' => (int) filesize($absolutePath),
                'checksum' => hash_file('sha256', $absolutePath) ?: null,
                'completed_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            @unlink($absolutePath);
            $backup->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->prune();

        return $backup->fresh(['creator:id,name,email']);
    }

    public function absolutePath(DatabaseBackup $backup): string
    {
        $path = Storage::disk($backup->disk)->path($backup->path);
        if (! is_file($path)) {
            throw new RuntimeException('Backup file is missing from storage.');
        }

        return $path;
    }

    public function delete(DatabaseBackup $backup): void
    {
        $disk = Storage::disk($backup->disk);
        if ($disk->exists($backup->path)) {
            $disk->delete($backup->path);
        }

        $backup->delete();
    }

    public function restore(DatabaseBackup $backup): void
    {
        if ($backup->status !== 'completed') {
            throw new RuntimeException('Only completed backups can be restored.');
        }

        $this->restoreFromPath($this->absolutePath($backup));
    }

    public function restoreUpload(UploadedFile $file): DatabaseBackup
    {
        if (! config('database-backup.enabled', true)) {
            throw new RuntimeException('Database backups are disabled.');
        }

        $this->assertToolsAvailable();

        $disk = (string) config('database-backup.disk', 'local');
        $directory = trim((string) config('database-backup.directory', 'database-backups'), '/');
        $timestamp = now()->format('Ymd_His');
        $original = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = Str::slug($original) ?: 'uploaded';
        $filename = "{$safeName}_{$timestamp}.dump";
        $relativePath = "{$directory}/{$filename}";

        $stored = $file->storeAs($directory, $filename, $disk);
        if (! $stored) {
            throw new RuntimeException('Failed to store uploaded backup.');
        }

        $absolutePath = Storage::disk($disk)->path($relativePath);
        $backup = DatabaseBackup::create([
            'filename' => $filename,
            'disk' => $disk,
            'path' => $relativePath,
            'type' => 'uploaded',
            'label' => 'Uploaded restore source',
            'size_bytes' => is_file($absolutePath) ? (int) filesize($absolutePath) : 0,
            'checksum' => is_file($absolutePath) ? (hash_file('sha256', $absolutePath) ?: null) : null,
            'status' => 'completed',
            'created_by' => auth()->id(),
            'completed_at' => now(),
        ]);

        $this->restoreFromPath($absolutePath);
        $this->prune();

        return $backup->fresh(['creator:id,name,email']);
    }

    /**
     * Remove backups older than retention and enforce max count.
     */
    public function prune(): int
    {
        $deleted = 0;
        $retentionDays = max(1, (int) config('database-backup.retention_days', 30));
        $maxBackups = max(1, (int) config('database-backup.max_backups', 120));
        $cutoff = now()->subDays($retentionDays);

        $expired = DatabaseBackup::query()
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->get();

        foreach ($expired as $backup) {
            $this->delete($backup);
            $deleted++;
        }

        $keepIds = DatabaseBackup::query()
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->limit($maxBackups)
            ->pluck('id');

        $overflow = DatabaseBackup::query()
            ->where('status', 'completed')
            ->when($keepIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $keepIds))
            ->get();

        foreach ($overflow as $backup) {
            $this->delete($backup);
            $deleted++;
        }

        // Clean orphaned failed records older than a day without files.
        DatabaseBackup::query()
            ->where('status', 'failed')
            ->where('created_at', '<', now()->subDay())
            ->delete();

        return $deleted;
    }

    private function restoreFromPath(string $absolutePath): void
    {
        $this->assertToolsAvailable();

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Backup file not found.');
        }

        $connection = config('database.default');
        DB::purge($connection);

        try {
            $this->runPgRestore($absolutePath);
        } finally {
            DB::reconnect($connection);
        }
    }

    private function runPgDump(string $absolutePath): void
    {
        $db = $this->pgsqlConfig();
        $binary = (string) config('database-backup.pg_dump_path', 'pg_dump');

        $process = new Process([
            $binary,
            '--host='.$db['host'],
            '--port='.$db['port'],
            '--username='.$db['username'],
            '--dbname='.$db['database'],
            '--format=custom',
            '--no-owner',
            '--no-acl',
            '--file='.$absolutePath,
        ]);

        $process->setTimeout(600);
        $process->setEnv([
            'PGPASSWORD' => (string) $db['password'],
            'PGSSLMODE' => 'prefer',
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'pg_dump failed.');
        }
    }

    private function runPgRestore(string $absolutePath): void
    {
        $db = $this->pgsqlConfig();
        $binary = (string) config('database-backup.pg_restore_path', 'pg_restore');

        $process = new Process([
            $binary,
            '--host='.$db['host'],
            '--port='.$db['port'],
            '--username='.$db['username'],
            '--dbname='.$db['database'],
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-acl',
            '--single-transaction',
            $absolutePath,
        ]);

        $process->setTimeout(1200);
        $process->setEnv([
            'PGPASSWORD' => (string) $db['password'],
            'PGSSLMODE' => 'prefer',
        ]);
        $process->run();

        // pg_restore may exit non-zero for ignorable warnings; treat hard failures only.
        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput() ?: $process->getOutput());
            if ($this->isFatalRestoreError($error)) {
                throw new RuntimeException($error ?: 'pg_restore failed.');
            }
        }
    }

    private function isFatalRestoreError(string $error): bool
    {
        if ($error === '') {
            return true;
        }

        $fatalMarkers = [
            'could not connect',
            'authentication failed',
            'does not exist',
            'permission denied',
            'fatal:',
            'error: input file appears to be a text format dump',
            'unrecognized archive format',
        ];

        $lower = strtolower($error);
        foreach ($fatalMarkers as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        // Warnings about objects that already exist / missing are common with --clean.
        return ! str_contains($lower, 'warning:');
    }

    /**
     * @return array{host:string,port:string,username:string,password:string,database:string}
     */
    private function pgsqlConfig(): array
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Database backups currently support PostgreSQL only.');
        }

        return [
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (string) ($config['port'] ?? '5432'),
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
            'database' => (string) ($config['database'] ?? ''),
        ];
    }

    private function assertToolsAvailable(): void
    {
        $dump = (string) config('database-backup.pg_dump_path', 'pg_dump');
        $restore = (string) config('database-backup.pg_restore_path', 'pg_restore');

        foreach ([$dump, $restore] as $binary) {
            $process = Process::fromShellCommandline('command -v '.escapeshellarg($binary));
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException("Required tool not found: {$binary}");
            }
        }
    }
}
