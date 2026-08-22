<?php

namespace Tests\Feature\Sync;

use App\Models\Device;
use App\Sync\PullRunner;
use App\Sync\PushRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S8-01, build-plan_1.md §3.7 — the real multi-connection harness
 * Tests\Feature\Sync\CloudTestCase's own docblock said Sprint 8 would
 * supersede it with, for scenarios that need a server and several
 * devices interacting *at once* (the 20 chaos scenarios, S8-03) rather
 * than one connection playing first one role then another.
 *
 * Four genuinely separate named SQLite :memory: connections
 * (config/database.php: server, device_a, device_b, device_c) coexist
 * simultaneously for the whole test — migrated fresh in setUp() every
 * test method, so there is no RefreshDatabaseState-style shared,
 * cross-test in-memory database to leak between tests the way Sprint 7
 * found CloudTestCase's single-connection, config-toggled approach
 * could (see that sprint's commit for the full story). Switching
 * "current" context here only ever changes *which already-fully-formed*
 * connection is the Eloquent default; it never re-points, re-migrates,
 * or disconnects one out from under itself.
 *
 * device_c exists solely for S18's three-way conflict scenario — every
 * other scenario uses just server/device_a/device_b.
 */
abstract class MultiDeviceTestCase extends TestCase
{
    /**
     * @var array<string, array{database.default: string, sync.mode: string, sync.device_id: ?string}>
     */
    protected const CONTEXTS = [
        'server' => ['database.default' => 'server', 'sync.mode' => 'cloud', 'sync.device_id' => null],
        'device_a' => ['database.default' => 'device_a', 'sync.mode' => 'local', 'sync.device_id' => 'DA1'],
        'device_b' => ['database.default' => 'device_b', 'sync.mode' => 'local', 'sync.device_id' => 'DB1'],
        'device_c' => ['database.default' => 'device_c', 'sync.mode' => 'local', 'sync.device_id' => 'DC1'],
    ];

    protected string $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(self::CONTEXTS) as $context) {
            $this->migrateConnection($context);
        }

        // customers.owner_user_id is a real FK to users on every
        // connection, shared or not — S6-07's real backup/restore
        // rehearsal already found that a device needs its own matching
        // local `users` row for anything owned by that user to insert at
        // all. One owner, shared by id across all four connections.
        $this->ownerId = (string) Str::uuid7();

        $this->onServer(function () {
            DB::table('users')->insert($this->userRow());

            foreach (self::CONTEXTS as $context => $config) {
                if ($config['sync.device_id'] === null) {
                    continue; // server itself isn't a device
                }

                Device::create([
                    'name' => $context,
                    'device_code' => $config['sync.device_id'],
                    'owner_user_id' => $this->ownerId,
                ]);
            }
        });

        foreach (['device_a', 'device_b', 'device_c'] as $context) {
            $this->withContext(self::CONTEXTS[$context], function () {
                DB::table('users')->insert($this->userRow());
            });
        }
    }

    protected function migrateConnection(string $context): void
    {
        $config = self::CONTEXTS[$context];
        $modePath = $config['sync.mode'] === 'cloud' ? 'cloud' : 'local';

        foreach (['shared', $modePath] as $set) {
            Artisan::call('migrate', [
                '--database' => $config['database.default'],
                '--path' => "database/migrations/{$set}",
                '--force' => true,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function userRow(): array
    {
        return [
            'id' => $this->ownerId,
            'name' => 'Field Rep',
            'email' => $this->ownerId.'@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * build-plan_1.md §3.7 — implemented exactly as written there. Public,
     * not protected: InProcessSyncApi (a separate class, not a subclass)
     * needs to call this on the harness instance it's handed.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function withContext(array $overrides, callable $fn): mixed
    {
        $prev = [];
        foreach ($overrides as $k => $v) {
            $prev[$k] = config($k);
        }

        config($overrides);
        DB::setDefaultConnection(config('database.default'));

        try {
            return $fn();
        } finally {
            config($prev);
            DB::setDefaultConnection(config('database.default'));
        }
    }

    public function onServer(callable $fn): mixed
    {
        return $this->withContext(self::CONTEXTS['server'], $fn);
    }

    protected function onDeviceA(callable $fn): mixed
    {
        return $this->withContext(self::CONTEXTS['device_a'], $fn);
    }

    protected function onDeviceB(callable $fn): mixed
    {
        return $this->withContext(self::CONTEXTS['device_b'], $fn);
    }

    protected function onDeviceC(callable $fn): mixed
    {
        return $this->withContext(self::CONTEXTS['device_c'], $fn);
    }

    /**
     * @param  'device_a'|'device_b'|'device_c'  $context
     */
    protected function apiFor(string $context, ?int $schemaVersion = null): InProcessSyncApi
    {
        $deviceCode = self::CONTEXTS[$context]['sync.device_id'];

        return new InProcessSyncApi($this, $deviceCode, $schemaVersion ?? (int) config('sync.schema_version'));
    }

    /**
     * "A syncs" — push then pull, run directly through the real
     * PushRunner/PullRunner (not the full SyncRunner: its probe/
     * handshake/lock/backoff orchestration is already covered by
     * tests/Feature/Sync/SyncRunnerTest.php; the chaos scenarios need
     * control over exactly this push-then-pull step, not that
     * bookkeeping around it).
     *
     * @param  'device_a'|'device_b'|'device_c'  $context
     * @return array{pushed: int, pulled: int}
     */
    protected function syncDevice(string $context, ?InProcessSyncApi $api = null): array
    {
        return $this->withContext(self::CONTEXTS[$context], function () use ($context, $api) {
            $api ??= $this->apiFor($context);

            return [
                'pushed' => app(PushRunner::class, ['api' => $api])->run(),
                'pulled' => app(PullRunner::class, ['api' => $api])->run(),
            ];
        });
    }

    /**
     * @param  'device_a'|'device_b'|'device_c'  $context
     */
    protected function pushFrom(string $context, ?InProcessSyncApi $api = null): int
    {
        return $this->withContext(
            self::CONTEXTS[$context],
            fn () => app(PushRunner::class, ['api' => $api ?? $this->apiFor($context)])->run(),
        );
    }

    /**
     * @param  'device_a'|'device_b'|'device_c'  $context
     */
    protected function pullTo(string $context, ?InProcessSyncApi $api = null, int $limit = 500): int
    {
        return $this->withContext(
            self::CONTEXTS[$context],
            fn () => app(PullRunner::class, ['api' => $api ?? $this->apiFor($context)])->run($limit),
        );
    }
}
