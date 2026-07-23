<?php

namespace App\Providers;

use App\Modules\Hr\Observers\EmployeeActivityObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
