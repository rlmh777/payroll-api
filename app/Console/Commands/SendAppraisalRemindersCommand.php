<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Hr\Services\Appraisal\AppraisalNotificationService;
use App\Support\TenantManager;
use Illuminate\Console\Command;
use Throwable;

class SendAppraisalRemindersCommand extends Command
{
    protected $signature = 'hr:send-appraisal-reminders
        {--tenant= : Tenant slug when tenancy is enabled}';

    protected $description = 'Email assignees about employee appraisals waiting on their action';

    public function handle(AppraisalNotificationService $notifications, TenantManager $tenants): int
    {
        $run = function (?string $slug) use ($notifications): void {
            $stats = $notifications->sendDueReminders();
            $this->info(sprintf(
                'Appraisal reminders%s: sent %d, skipped %d, errors %d.',
                $slug ? " [{$slug}]" : '',
                $stats['sent'],
                $stats['skipped'],
                $stats['errors'],
            ));
        };

        if ($tenants->enabled()) {
            $requested = trim((string) $this->option('tenant'));
            try {
                $query = Tenant::query()->where('enabled', true);
                if ($requested !== '') {
                    $query->where('slug', $tenants->normalizeSlug($requested));
                }
                $list = $query->orderBy('slug')->get();
            } catch (Throwable) {
                $list = collect();
            }

            if ($list->isEmpty()) {
                $run($tenants->normalizeSlug($requested !== '' ? $requested : (string) config('tenancy.default')));

                return self::SUCCESS;
            }

            $failed = 0;
            foreach ($list as $tenant) {
                try {
                    $tenants->initialize($tenant);
                    $run($tenant->slug);
                } catch (Throwable $exception) {
                    $this->error("Failed for [{$tenant->slug}]: ".$exception->getMessage());
                    $failed++;
                }
            }
            $tenants->forget();

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $run(null);

        return self::SUCCESS;
    }
}
