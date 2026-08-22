<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use App\Sync\SyncRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush(); // Connectivity's 30s ping cache must not leak between tests
    }

    private function fakeHappyPath(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/api/sync/ping')) {
                return Http::response('', 204);
            }
            if (str_contains($url, '/api/sync/handshake')) {
                return Http::response(['server_time' => 'x', 'schema_version' => 1]);
            }
            if (str_contains($url, '/api/sync/push')) {
                return Http::response(['results' => []]);
            }

            return Http::response(['changes' => [], 'cursor' => 0]); // pull
        });
    }

    public function test_a_full_success_cycle_runs_probe_handshake_push_pull_and_logs_it(): void
    {
        $owner = User::factory()->create();
        Customer::create(['name' => 'Acme', 'owner_user_id' => $owner->id]); // a pending outbox row — push() short-circuits without one

        $this->fakeHappyPath();

        $ran = app(SyncRunner::class)->run();

        $this->assertTrue($ran);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/sync/ping'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/sync/handshake'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/sync/push'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/sync/pull'));

        $run = DB::table('sync_runs')->latest('id')->first();
        $this->assertSame('success', $run->status);
        $this->assertNotNull($run->finished_at);
    }

    public function test_when_offline_it_logs_offline_and_never_calls_handshake(): void
    {
        Http::fake(fn () => Http::response('', 500));

        app(SyncRunner::class)->run();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/sync/handshake'));

        $run = DB::table('sync_runs')->latest('id')->first();
        $this->assertSame('offline', $run->status);
        $this->assertSame('probe', $run->stage);
    }

    public function test_a_failure_mid_cycle_is_logged_with_the_failing_stage(): void
    {
        // PushRunner returns early without an HTTP call when the outbox is
        // empty — a real pending change is what makes push's own fake
        // branch (not pull's) the one that actually throws.
        $owner = User::factory()->create();
        Customer::create(['name' => 'Acme', 'owner_user_id' => $owner->id]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/api/sync/ping')) {
                return Http::response('', 204);
            }
            if (str_contains($url, '/api/sync/handshake')) {
                return Http::response(['server_time' => 'x', 'schema_version' => 1]);
            }

            throw new ConnectionException('down'); // push, and anything after
        });

        app(SyncRunner::class)->run();

        $run = DB::table('sync_runs')->latest('id')->first();
        $this->assertSame('failed', $run->status);
        $this->assertSame('push', $run->stage);
        $this->assertNotNull($run->error);
    }

    public function test_two_concurrent_runs_exactly_one_executes(): void
    {
        $this->fakeHappyPath();

        // Simulate "already running" the way SyncRunner itself would leave
        // things mid-flight: hold the exact lock it uses.
        $lock = Cache::lock('sync:run', 300);
        $this->assertTrue($lock->get());

        $ran = app(SyncRunner::class)->run();

        $this->assertFalse($ran, 'a concurrent run must not execute while the lock is held');
        $this->assertSame(0, DB::table('sync_runs')->count());

        $lock->release();

        $this->assertTrue(app(SyncRunner::class)->run());
    }

    public function test_skips_without_running_while_backoff_is_active(): void
    {
        DB::table('sync_runs')->insert([
            'status' => 'failed',
            'started_at' => now()->subSeconds(5),
            'finished_at' => now()->subSeconds(5),
            'created_at' => now()->subSeconds(5),
            'updated_at' => now()->subSeconds(5),
        ]);

        Http::fake();

        $ran = app(SyncRunner::class)->run();

        $this->assertFalse($ran);
        Http::assertNothingSent();
    }
}
