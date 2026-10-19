<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Hr\Services\Appraisal\AppraisalScheduleService;
use App\Support\TenantManager;
use Illuminate\Console\Command;
use Throwable;

class StartDueAppraisalsCommand extends Command
{
    protected $signature = 'hr:start-due-appraisals
        {--tenant= : Tenant slug when tenancy is enabled}';

    protected $description = 'Start appraisals that are due three months before the contract or appraisal date';

    public function handle(AppraisalScheduleService $schedule, TenantManager $tenants): int
    {
        $run = function (?string $slug) use ($schedule): void {
            $stats = $schedule->startDue();
            $this->info(sprintf(
                'Due appraisals%s: started %d, skipped %d.',
                $slug ? " [{$slug}]" : '',
                $stats['started'],
                $stats['skipped'],
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
