<?php

namespace Tests\Feature\Sync;

use App\Sync\Connectivity;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectivityTest extends TestCase
{
    public function test_online_is_true_when_the_ping_succeeds(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $this->assertTrue(app(Connectivity::class)->online());
    }

    public function test_online_is_false_when_the_ping_fails(): void
    {
        // Http::fake() calls are additive (stub callbacks merge in
        // registration order, first match wins) — a fresh fake here, not a
        // second call layered on another test's, is what actually takes
        // effect for this assertion.
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertFalse(app(Connectivity::class)->online());
    }

    public function test_result_is_cached_for_30_seconds(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response('', 204);
        });

        $connectivity = app(Connectivity::class);
        $connectivity->online();
        $connectivity->online();
        $connectivity->online();

        $this->assertSame(1, $calls, 'a single ping should serve all three calls within the cache window');
    }

    public function test_forget_clears_the_cache(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response('', 204);
        });

        $connectivity = app(Connectivity::class);
        $connectivity->online();
        $connectivity->forget();
        $connectivity->online();

        $this->assertSame(2, $calls);
    }
}
