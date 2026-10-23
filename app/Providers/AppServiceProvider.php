<?php

namespace App\Providers;

use App\Services\Relay\RelaySettings;
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
