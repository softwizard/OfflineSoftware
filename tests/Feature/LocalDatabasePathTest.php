<?php

namespace Tests\Feature;

use App\Providers\SyncServiceProvider;
use App\Sync\LocalDatabasePath;
use App\Sync\OneDriveRedirectionException;
use Tests\TestCase;

/**
 * S7-02 — "asserting the path is not OneDrive-redirected." Every test
 * here saves and restores the real LOCALAPPDATA/OneDrive* environment
 * variables around itself: this machine genuinely has OneDrive installed
 * (OneDrive=C:\Users\...\OneDrive, LOCALAPPDATA=C:\Users\...\AppData\Local
 * — two different, non-overlapping paths, which is exactly the healthy
 * case), so a test that forgot to restore these would leak into every
 * other test's environment for the rest of the process.
 */
class LocalDatabasePathTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['LOCALAPPDATA', 'OneDrive', 'OneDriveCommercial', 'OneDriveConsumer'] as $key) {
            $this->originalEnv[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
        }

        parent::tearDown();
    }

    public function test_passes_when_localappdata_is_not_inside_any_onedrive_root(): void
    {
        putenv('LOCALAPPDATA=C:\\Users\\Someone\\AppData\\Local');
        putenv('OneDrive=C:\\Users\\Someone\\OneDrive');
        putenv('OneDriveConsumer=C:\\Users\\Someone\\OneDrive');

        LocalDatabasePath::assertNotOneDriveRedirected();

        $this->assertTrue(true);
    }

    public function test_passes_when_no_onedrive_env_vars_are_set_at_all(): void
    {
        putenv('LOCALAPPDATA=C:\\Users\\Someone\\AppData\\Local');
        putenv('OneDrive');
        putenv('OneDriveCommercial');
        putenv('OneDriveConsumer');

        LocalDatabasePath::assertNotOneDriveRedirected();

        $this->assertTrue(true);
    }

    public function test_passes_when_localappdata_itself_is_not_set(): void
    {
        putenv('LOCALAPPDATA');
        putenv('OneDrive=C:\\Users\\Someone\\OneDrive');

        // resolve() itself falls back to database/ in this case (Sprint 0)
        // — nothing OneDrive could redirect, so nothing to assert here.
        LocalDatabasePath::assertNotOneDriveRedirected();

        $this->assertTrue(true);
    }

    public function test_throws_when_localappdata_is_inside_the_consumer_onedrive_root(): void
    {
        putenv('LOCALAPPDATA=C:\\Users\\Someone\\OneDrive\\AppData\\Local');
        putenv('OneDrive=C:\\Users\\Someone\\OneDrive');
        putenv('OneDriveConsumer=C:\\Users\\Someone\\OneDrive');

        $this->expectException(OneDriveRedirectionException::class);
        $this->expectExceptionMessage('OneDrive-synced folder');

        LocalDatabasePath::assertNotOneDriveRedirected();
    }

    public function test_throws_when_localappdata_is_inside_the_commercial_onedrive_root(): void
    {
        putenv('LOCALAPPDATA=C:\\Users\\Someone\\OneDrive - Acme Corp\\AppData\\Local');
        putenv('OneDrive');
        putenv('OneDriveCommercial=C:\\Users\\Someone\\OneDrive - Acme Corp');
        putenv('OneDriveConsumer');

        $this->expectException(OneDriveRedirectionException::class);

        LocalDatabasePath::assertNotOneDriveRedirected();
    }

    public function test_comparison_is_case_insensitive_and_separator_normalized(): void
    {
        // Windows paths are case-insensitive and env vars can disagree on
        // trailing slashes / slash direction — a real redirection must
        // still be caught regardless of exactly how each var is cased.
        putenv('LOCALAPPDATA=c:/users/someone/onedrive/appdata/local/');
        putenv('OneDrive=C:\\Users\\Someone\\OneDrive');

        $this->expectException(OneDriveRedirectionException::class);

        LocalDatabasePath::assertNotOneDriveRedirected();
    }

    public function test_a_similarly_named_but_unrelated_folder_is_not_a_false_positive(): void
    {
        // "OneDriveBackup" starts with the same characters as "OneDrive"
        // but is not the same folder — a naive substring/str_starts_with
        // on the raw paths (without the trailing separator normalization)
        // would wrongly flag this.
        putenv('LOCALAPPDATA=C:\\Users\\Someone\\OneDriveBackup\\AppData\\Local');
        putenv('OneDrive=C:\\Users\\Someone\\OneDrive');

        LocalDatabasePath::assertNotOneDriveRedirected();

        $this->assertTrue(true);
    }

    public function test_sync_service_provider_boot_fails_loudly_when_redirected(): void
    {
        config(['sync.mode' => 'local', 'sync.device_id' => 'D01']);
        putenv('LOCALAPPDATA=C:\\Users\\Someone\\OneDrive\\AppData\\Local');
        putenv('OneDrive=C:\\Users\\Someone\\OneDrive');

        $this->expectException(OneDriveRedirectionException::class);

        (new SyncServiceProvider($this->app))->boot();
    }
}
