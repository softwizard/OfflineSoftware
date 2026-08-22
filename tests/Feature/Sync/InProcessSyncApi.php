<?php

namespace Tests\Feature\Sync;

use App\Http\Controllers\Sync\HandshakeController;
use App\Http\Controllers\Sync\PullController;
use App\Http\Controllers\Sync\PushController;
use App\Http\Controllers\Sync\SnapshotController;
use App\Models\Device;
use App\Sync\Api\DeviceRevokedException;
use App\Sync\Api\SyncApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * S8-02, build-plan_1.md §3.7 — "switches to the server context, invokes
 * the controller directly, and switches back. No HTTP, no ports,
 * deterministic in CI." Bypassing the HTTP layer means bypassing the
 * real AuthenticateDevice/EnforceSchemaVersion middleware too, so this
 * replicates their two checks itself — device revocation (S15) and an
 * outdated client schema (S12) are exactly the protocol-level failures
 * the chaos scenarios need to provoke, not just the happy path.
 */
class InProcessSyncApi implements SyncApi
{
    /**
     * S06/S19 — fault injection. There is no real socket to unplug on an
     * in-process double, so transport failure has to be simulated
     * explicitly: failPushesRemaining counts pushes left to fail;
     * failPullsAfterPages counts pull *pages* to let through before
     * failing the next one (-1 disables it).
     */
    protected int $failPushesRemaining = 0;

    protected int $failPullsAfterPages = -1;

    public function __construct(
        protected MultiDeviceTestCase $harness,
        protected string $deviceCode,
        protected int $schemaVersion,
    ) {}

    public function failNextPush(int $times = 1): static
    {
        $this->failPushesRemaining = $times;

        return $this;
    }

    public function failPullAfterPages(int $successfulPages): static
    {
        $this->failPullsAfterPages = $successfulPages;

        return $this;
    }

    public function ping(): bool
    {
        return true; // in-process — there is no network to ever be down
    }

    /**
     * @return array<string, mixed>
     */
    public function handshake(): array
    {
        $this->guard();

        return $this->harness->onServer(
            fn () => app(HandshakeController::class)->store()->getData(true)
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<string, mixed>
     */
    public function push(array $changes): array
    {
        if ($this->failPushesRemaining > 0) {
            $this->failPushesRemaining--;

            throw new ConnectionException('simulated transport failure (in-process double)');
        }

        $device = $this->guard();

        return $this->harness->onServer(function () use ($changes, $device) {
            $request = Request::create('/api/sync/push', 'POST', ['changes' => $changes]);
            $request->attributes->set('device', $device);

            return app(PushController::class)->store($request)->getData(true);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function pull(int $since, int $limit = 500): array
    {
        if ($this->failPullsAfterPages === 0) {
            $this->failPullsAfterPages = -1; // one-shot — the retried pull must succeed

            throw new ConnectionException('simulated transport failure mid-pull (in-process double)');
        }

        if ($this->failPullsAfterPages > 0) {
            $this->failPullsAfterPages--;
        }

        $device = $this->guard();

        return $this->harness->onServer(function () use ($since, $limit, $device) {
            $request = Request::create('/api/sync/pull', 'GET', ['since' => $since, 'limit' => $limit]);
            $request->attributes->set('device', $device);

            return app(PullController::class)->index($request)->getData(true);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $device = $this->guard();

        return $this->harness->onServer(function () use ($device) {
            $request = Request::create('/api/sync/snapshot', 'GET');
            $request->attributes->set('device', $device);

            return app(SnapshotController::class)->show($request)->getData(true);
        });
    }

    /**
     * AuthenticateDevice + EnforceSchemaVersion's checks, replicated —
     * this device double never actually travels through either
     * middleware, so nothing else would ever enforce them.
     */
    protected function guard(): Device
    {
        if ($this->schemaVersion < (int) config('sync.min_supported_schema_version')) {
            throw new RuntimeException('schema_outdated (in-process double)');
        }

        $device = $this->resolveDevice();

        if ($device->revoked_at !== null) {
            throw new DeviceRevokedException('This device has been revoked by the server.');
        }

        return $device;
    }

    protected function resolveDevice(): Device
    {
        return $this->harness->onServer(
            fn () => Device::where('device_code', $this->deviceCode)->firstOrFail()
        );
    }
}
