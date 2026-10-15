<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Hr\Services\ContractExpiryReminderService;
use App\Support\TenantManager;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class SendContractExpiryRemindersCommand extends Command
{
    protected $signature = 'hr:send-contract-expiry-reminders
        {--date= : Run as of this date (Y-m-d)}
        {--tenant= : Tenant slug when tenancy is enabled}';

    protected $description = 'Email supervisors, HR, and GMs about employment contracts nearing expiry';

    public function handle(ContractExpiryReminderService $reminders, TenantManager $tenants): int
    {
        $dateOption = trim((string) $this->option('date'));
        $asOf = $dateOption !== '' ? Carbon::parse($dateOption)->startOfDay() : now()->startOfDay();

        $run = function (?string $slug) use ($reminders, $asOf): void {
            $stats = $reminders->sendDueReminders($asOf);
            $this->info(sprintf(
                'Contract expiry reminders%s: sent %d, skipped %d, errors %d.',
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
