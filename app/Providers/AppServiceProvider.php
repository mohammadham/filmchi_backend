<?php

namespace App\Providers;

use App\Services\Relay\RelaySettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);

        $this->applyRelaySettings();
        $this->defineRateLimiters();
    }

    /**
     * Named rate limiters for the public relay endpoints and the login form.
     *
     * The streams are cheap per request but easy to hammer; the login is the
     * only credential gate on the panel.
     */
    protected function defineRateLimiters(): void
    {
        RateLimiter::for('relayStream', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        RateLimiter::for('panelLogin', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }

    /**
     * Panel-saved relay connection settings override the .env defaults.
     *
     * Wrapped defensively: the table may not exist yet (fresh install,
     * migrations, or a test database) and that must never break a request.
     */
    protected function applyRelaySettings(): void
    {
        try {
            if (!Schema::hasTable('global_settings')) {
                return;
            }

            RelaySettings::apply();
        } catch (\Throwable $e) {
            // Ignore: config falls back to the .env values.
        }
    }
}
