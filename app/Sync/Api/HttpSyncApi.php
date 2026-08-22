<?php

namespace App\Sync\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpSyncApi implements SyncApi
{
    public function __construct(
        protected string $baseUrl,
        protected string $token,
    ) {}

    public function ping(): bool
    {
        try {
            // No retry, no schema header, 3s — Connectivity needs a fast
            // fail, not a best-effort connection.
            return Http::baseUrl($this->baseUrl)->timeout(3)->head('/api/sync/ping')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function handshake(): array
    {
        return $this->client()->post('/api/sync/handshake')->throw()->json();
    }

    public function push(array $changes): array
    {
        return $this->client()->post('/api/sync/push', ['changes' => $changes])->throw()->json();
    }

    public function pull(int $since, int $limit = 500): array
    {
        return $this->client()->get('/api/sync/pull', ['since' => $since, 'limit' => $limit])->throw()->json();
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->withHeaders(['X-Sync-Schema-Version' => (string) config('sync.schema_version')])
            ->timeout(10)
            // Laravel's retry() retries on ANY failed response by default,
            // not just transport failures — without this $when callback, a
            // 409 schema_outdated would get retried 3 times for nothing;
            // it needs a new client build, not a resend. Only a genuine
            // ConnectionException (DNS, dropped connection, refused) retries.
            ->retry(3, 1000, fn (Throwable $e) => $e instanceof ConnectionException);
    }
}
