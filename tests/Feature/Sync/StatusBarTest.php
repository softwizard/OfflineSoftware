<?php

namespace Tests\Feature\Sync;

use App\Livewire\Sync\StatusBar;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class StatusBarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_shows_online_when_the_server_is_reachable(): void
    {
        Http::fake(fn () => Http::response('', 204));

        Livewire::test(StatusBar::class)->assertSee('Online');
    }

    public function test_shows_offline_when_the_server_is_not_reachable(): void
    {
        Http::fake(fn () => Http::response('', 500));

        Livewire::test(StatusBar::class)->assertSee('Offline');
    }

    public function test_shows_the_pending_count(): void
    {
        Http::fake(fn () => Http::response('', 204));
        $owner = User::factory()->create();
        Customer::factory()->create(['owner_user_id' => $owner->id]);

        Livewire::test(StatusBar::class)->assertSee('1 change pending');
    }

    public function test_shows_never_synced_before_any_successful_run(): void
    {
        Http::fake(fn () => Http::response('', 204));

        Livewire::test(StatusBar::class)->assertSee('Never synced');
    }

    public function test_sync_now_triggers_a_run_and_refreshes_the_display(): void
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

        Livewire::test(StatusBar::class)
            ->call('syncNow')
            ->assertSee('Last synced');

        $this->assertNotNull(DB::table('sync_state')->where('id', 1)->value('last_success_at'));
    }
}
