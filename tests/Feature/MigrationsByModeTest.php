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
}
