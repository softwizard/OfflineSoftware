<?php

namespace App\Providers;

use App\Sync\Api\HttpSyncApi;
use App\Sync\Api\SyncApi;
use App\Sync\DeviceIdentity;
use Illuminate\Support\ServiceProvider;

class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Sprint 8's InProcessSyncApi (S8-02) rebinds this in tests that
        // need it; production always talks over real HTTP.
        $this->app->bind(SyncApi::class, fn () => new HttpSyncApi(
            (string) config('sync.server_url'),
            (string) config('sync.device_token'),
        ));
    }

    /**
     * Fail loudly, not silently: a local install with no device identity
     * must not boot far enough to write data under a blank origin_device.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/shared'));
        $this->loadMigrationsFrom(static::modeMigrationPath());

        if (sync_is_local()) {
            DeviceIdentity::id();
        }
    }

    /**
     * The mode-specific migration path — split out from boot() so the
     * mode-branching logic can be asserted without touching the shared,
     * state-accumulating Migrator singleton.
     */
    public static function modeMigrationPath(): string
    {
        return database_path(sync_is_cloud() ? 'migrations/cloud' : 'migrations/local');
    }
}
