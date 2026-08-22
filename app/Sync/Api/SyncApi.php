<?php

namespace App\Sync\Api;

/**
 * §3.7 — production binds HttpSyncApi. Sprint 8's InProcessSyncApi (a test
 * double that switches to the server context and invokes the controller
 * directly — no HTTP, no ports) implements this same contract; not built
 * yet, that's S8-02.
 */
interface SyncApi
{
    public function ping(): bool;

    /**
     * @return array<string, mixed>
     */
    public function handshake(): array;

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<string, mixed>
     */
    public function push(array $changes): array;

    /**
     * @return array<string, mixed>
     */
    public function pull(int $since, int $limit = 500): array;
}
