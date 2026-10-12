<?php

namespace App\Providers;

use App\Domain\Authorization\BootstrapAdmin;
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
        // Web requests only: console runs (migrate, tests) skip the bootstrap so
        // no query hits `users` before it exists. BootstrapAdmin is tested directly.
        if (! app()->runningInConsole()) {
            app(BootstrapAdmin::class)->ensure();
        }
    }
}
