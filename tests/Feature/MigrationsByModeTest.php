<?php

namespace Tests\Feature;

use App\Providers\SyncServiceProvider;
use Tests\TestCase;

class MigrationsByModeTest extends TestCase
{
    public function test_local_mode_resolves_to_the_local_migration_path(): void
    {
        config(['sync.mode' => 'local']);

        $this->assertSame(database_path('migrations/local'), SyncServiceProvider::modeMigrationPath());
    }

    public function test_cloud_mode_resolves_to_the_cloud_migration_path(): void
    {
        config(['sync.mode' => 'cloud']);

        $this->assertSame(database_path('migrations/cloud'), SyncServiceProvider::modeMigrationPath());
    }

    public function test_shared_migration_path_is_registered_on_the_real_migrator(): void
    {
        $paths = $this->app->make('migrator')->paths();

        $this->assertContains(database_path('migrations/shared'), $paths);
    }

    /**
     * F3 — config/sync.php's 'mode' has no default (env('APP_MODE'),
     * not env('APP_MODE', 'local')): a server that silently booted in
     * local mode would keep an outbox instead of writing the canonical
     * sync_log, and every device would silently stop receiving changes.
     */
    public function test_boot_throws_when_the_mode_is_unset(): void
    {
        config(['sync.mode' => null, 'sync.device_id' => 'D01']);

        try {
            (new SyncServiceProvider($this->app))->boot();
            $this->fail('Expected a RuntimeException naming APP_MODE.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('APP_MODE', $e->getMessage());
        }
    }

    public function test_boot_throws_when_the_mode_is_neither_local_nor_cloud(): void
    {
        config(['sync.mode' => 'production', 'sync.device_id' => 'D01']);

        try {
            (new SyncServiceProvider($this->app))->boot();
            $this->fail('Expected a RuntimeException naming APP_MODE.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('APP_MODE', $e->getMessage());
        }
    }

    public function test_boot_succeeds_for_exactly_local_or_cloud(): void
    {
        config(['sync.mode' => 'local', 'sync.device_id' => 'D01']);
        (new SyncServiceProvider($this->app))->boot();

        config(['sync.mode' => 'cloud', 'sync.device_id' => null]);
        (new SyncServiceProvider($this->app))->boot();

        $this->assertTrue(true);
    }
}
