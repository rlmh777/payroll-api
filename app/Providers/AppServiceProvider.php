<?php

namespace App\Providers;

use App\Modules\Hr\Observers\EmployeeActivityObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Support\TenantManager::class);
        $this->app->singleton(\App\Support\ConfiguredStorage::class);
        $this->app->singleton(\App\Support\ConfiguredMail::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sail's php built-in server uses php.ini max_execution_time (30 by default).
        // Keep a floor of 600s for long-running HTTP work (imports, reports, bcrypt).
        if (! app()->runningInConsole()) {
            $current = (int) ini_get('max_execution_time');
            if ($current > 0 && $current < 600) {
                ini_set('max_execution_time', '600');
            }
        }

        RateLimiter::for('login', function (Request $request) {
            $identity = strtolower((string) (
                $request->input('username')
                ?: $request->input('email')
                ?: $request->ip()
            ));

            return Limit::perMinute(10)->by($identity.'|'.$request->ip());
        });

        RateLimiter::for('sensitive-action', function (Request $request) {
            $userId = (string) ($request->user()?->id ?: $request->ip());

            return Limit::perMinute(5)->by($userId);
        });

        if (! config('employee-activity.enabled', true)) {
            return;
        }

        $observer = $this->app->make(EmployeeActivityObserver::class);

        foreach (config('employee-activity.models', []) as $modelClass) {
            if (! is_string($modelClass) || ! class_exists($modelClass)) {
                continue;
            }

            if (! is_subclass_of($modelClass, Model::class)) {
                continue;
            }

            $modelClass::observe($observer);
        }
    }
}
