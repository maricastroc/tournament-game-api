<?php

namespace App\Providers;

use App\Support\Sse\PollingRevisionChannel;
use App\Support\Sse\RedisRevisionChannel;
use App\Support\Sse\RevisionChannel;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RevisionChannel::class, function ($app): RevisionChannel {
            $config = $app['config'];

            if ($config->get('sse.driver') === 'redis') {
                return new RedisRevisionChannel((string) $config->get('sse.redis_connection', 'default'));
            }

            return new PollingRevisionChannel((int) $config->get('sse.poll_ms', 1500));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('viewApiDocs', fn ($user = null) => true);

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by($email !== '' ? $email.'|'.$ip : $ip),
                Limit::perMinute(20)->by($ip),
            ];
        });
    }
}
