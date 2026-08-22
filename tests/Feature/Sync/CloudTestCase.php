<?php

namespace Tests\Feature\Sync;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

/**
 * Boots the app with APP_MODE=cloud so SyncServiceProvider loads the cloud
 * migrations (devices, sync_sequence, sync_log, ...) instead of local's —
 * that decision is made once at boot, so it has to be set before
 * parent::setUp() creates the application, not after via config().
 *
 * A stand-in for Sprint 8's real multi-connection test harness (§3.7):
 * this switches env for a whole test class against one (SQLite :memory:)
 * connection, where S8-01 will run server/device_a/device_b/device_c
 * against four real connections simultaneously. Good enough for testing
 * cloud-side behavior in isolation now; expect it to be superseded there.
 */
abstract class CloudTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('APP_MODE=cloud');
        $_SERVER['APP_MODE'] = 'cloud';
        $_ENV['APP_MODE'] = 'cloud';

        putenv('SYNC_DEVICE_ID');
        unset($_SERVER['SYNC_DEVICE_ID'], $_ENV['SYNC_DEVICE_ID']);

        // RefreshDatabase migrates once per *process*, not once per test —
        // RefreshDatabaseState::$migrated is a static flag shared across
        // every test class in this run. Without resetting it here, a local
        // -mode test that already migrated first would leave this test
        // silently reusing the local schema (no devices/sync_log/... tables);
        // reset again in tearDown() so the *next* test — local or cloud —
        // isn't left reusing this one's cloud schema either.
        RefreshDatabaseState::$migrated = false;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('APP_MODE=local');
        $_SERVER['APP_MODE'] = 'local';
        $_ENV['APP_MODE'] = 'local';

        putenv('SYNC_DEVICE_ID=D01');
        $_SERVER['SYNC_DEVICE_ID'] = 'D01';
        $_ENV['SYNC_DEVICE_ID'] = 'D01';

        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }
}
