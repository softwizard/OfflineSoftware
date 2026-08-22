<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Sync\SyncWipe;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncWipeTest extends TestCase
{
    use RefreshDatabase;

    public function test_wipe_erases_every_syncable_row(): void
    {
        Customer::factory(3)->create();

        app(SyncWipe::class)->wipe();

        $this->assertSame(0, Customer::withTrashed()->count());
    }

    public function test_wipe_clears_the_outbox(): void
    {
        Customer::factory()->create();
        $this->assertGreaterThan(0, DB::table('sync_changes')->count());

        app(SyncWipe::class)->wipe();

        $this->assertSame(0, DB::table('sync_changes')->count());
    }

    public function test_wipe_resets_the_pull_cursor_and_last_success(): void
    {
        DB::table('sync_state')->where('id', 1)->update(['cursor' => 500, 'last_success_at' => now()]);

        app(SyncWipe::class)->wipe();

        $state = DB::table('sync_state')->where('id', 1)->first();
        $this->assertSame(0, (int) $state->cursor);
        $this->assertNull($state->last_success_at);
    }

    public function test_sync_wipe_command_erases_data_when_forced(): void
    {
        Customer::factory()->create();

        $this->artisan('sync:wipe', ['--force' => true])->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, Customer::withTrashed()->count());
    }

    public function test_sync_wipe_command_aborts_without_confirmation(): void
    {
        Customer::factory()->create();

        $this->artisan('sync:wipe')
            ->expectsConfirmation('This permanently erases all local customer data and pending changes. Continue?', 'no')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(1, Customer::count());
    }

    public function test_sync_wipe_command_refuses_to_run_in_cloud_mode(): void
    {
        config(['sync.mode' => 'cloud']);

        $this->artisan('sync:wipe', ['--force' => true])->assertExitCode(Command::FAILURE);
    }

    public function test_wipe_blanks_a_previously_registered_device_token(): void
    {
        // Deliberately isolated from the real project .env — pointing
        // useEnvironmentPath() at a scratch directory means this exercises
        // the exact same file-rewrite SyncRegister's updateEnv() already
        // uses, without ever touching this repo's actual .env file.
        $scratchDir = sys_get_temp_dir().'/sync-wipe-test-'.uniqid();
        mkdir($scratchDir);
        file_put_contents($scratchDir.'/.env', "APP_NAME=Test\nSYNC_DEVICE_TOKEN=some-real-token-value\nSYNC_DEVICE_ID=D01\n");

        $this->app->useEnvironmentPath($scratchDir);

        try {
            app(SyncWipe::class)->wipe();

            $contents = file_get_contents($scratchDir.'/.env');
            $this->assertStringNotContainsString('some-real-token-value', $contents);
            $this->assertMatchesRegularExpression('/^SYNC_DEVICE_TOKEN=$/m', $contents);
            $this->assertStringContainsString('SYNC_DEVICE_ID=D01', $contents); // untouched
        } finally {
            unlink($scratchDir.'/.env');
            rmdir($scratchDir);
        }
    }
}
