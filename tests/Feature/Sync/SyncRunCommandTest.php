<?php

namespace Tests\Feature\Sync;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRunCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_sync_run_executes_a_cycle_and_fills_sync_runs(): void
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

            return Http::response(['changes' => [], 'cursor' => 0]);
        });

        $this->artisan('sync:run')->assertExitCode(0);

        $this->assertSame(1, DB::table('sync_runs')->count());
    }

    public function test_the_scheduler_registers_sync_run_every_five_minutes(): void
    {
        $events = app(Schedule::class)->events();

        $syncRunEvent = collect($events)->first(
            fn ($event) => str_contains($event->command ?? '', 'sync:run')
        );

        $this->assertNotNull($syncRunEvent, 'sync:run must be registered on the scheduler');
        $this->assertSame('*/5 * * * *', $syncRunEvent->expression);
    }
}
