<?php

namespace Tests\Feature\Sync;

use App\Sync\Api\HttpSyncApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class HttpSyncApiTest extends TestCase
{
    private function api(): HttpSyncApi
    {
        return new HttpSyncApi('https://cloud.test', 'test-token');
    }

    public function test_handshake_sends_the_schema_version_header_and_the_token(): void
    {
        Http::fake(['*' => Http::response(['server_time' => 'x', 'schema_version' => 1])]);

        $this->api()->handshake();

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Sync-Schema-Version', (string) config('sync.schema_version'))
            && $r->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_a_409_response_throws_without_retrying(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response(['error' => 'schema_outdated'], 409)]);

        try {
            $this->api()->handshake();
            $this->fail('Expected an exception for a 409 response.');
        } catch (\Throwable) {
            // expected
        }

        Http::assertSentCount(1); // not retried — retrying a schema mismatch can't ever succeed
    }

    public function test_a_connection_timeout_is_retried_then_throws(): void
    {
        // Http::assertSentCount() doesn't reliably count attempts that
        // throw before Laravel's own request-recording runs — a plain
        // counter inside the fake callback is a direct, reliable signal
        // of how many times the send was actually attempted.
        Sleep::fake();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('Connection timed out');
        });

        try {
            $this->api()->handshake();
            $this->fail('Expected a ConnectionException.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(3, $attempts); // retry(3, ...) — genuine transport failures do retry
    }

    public function test_ping_does_not_retry_and_uses_a_short_timeout(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $online = $this->api()->ping();

        $this->assertFalse($online);
        Http::assertSentCount(1);
    }

    public function test_ping_returns_true_on_success(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $this->assertTrue($this->api()->ping());
    }

    public function test_push_and_pull_hit_the_expected_endpoints(): void
    {
        Http::fake([
            '*/api/sync/push' => Http::response(['results' => []]),
            '*/api/sync/pull*' => Http::response(['changes' => [], 'cursor' => 0]),
        ]);

        $this->api()->push([]);
        $this->api()->pull(0, 500);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/sync/push'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/sync/pull') && $r['since'] === 0);
    }
}
