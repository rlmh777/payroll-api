<?php

namespace App\Console\Commands;

use App\Modules\Core\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Throwable;

class CreateDatabaseBackupCommand extends Command
{
    protected $signature = 'db:backup
        {--manual : Mark the backup as a manual run}
        {--label= : Optional label for the backup}';

    protected $description = 'Create a PostgreSQL database backup and prune old copies';

    public function handle(DatabaseBackupService $databaseBackupService): int
    {
        if (! config('database-backup.enabled', true)) {
            $this->warn('Database backups are disabled.');

            return self::SUCCESS;
        }

        $type = $this->option('manual') ? 'manual' : 'scheduled';
        $label = $this->option('label');

        try {
            $backup = $databaseBackupService->create(
                $type,
                is_string($label) && $label !== '' ? $label : null,
            );
            $this->info(sprintf(
                'Backup created: %s (%s bytes)',
                $backup->filename,
                number_format((int) $backup->size_bytes),
            ));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
