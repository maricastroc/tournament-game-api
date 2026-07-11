<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
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
        // Unlocks the Scramble API docs outside local when the request carries
        // the shared key (?key=<DOCS_KEY>). Local is always allowed upstream.
        Gate::define('viewApiDocs', function ($user = null) {
            $key = config('app.docs_key');

            return filled($key) && hash_equals($key, (string) request('key'));
        });
    }
}
