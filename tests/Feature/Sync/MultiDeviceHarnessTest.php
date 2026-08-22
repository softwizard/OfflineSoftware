<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Sync\Api\DeviceRevokedException;
use App\Sync\PullRunner;
use App\Sync\PushRunner;
use App\Sync\RemoteApplier;
use App\Sync\SyncContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * S8-01/S8-02's own acceptance criteria: "device/server switching provably
 * works" and "full round trips run with no HTTP." Not a chaos scenario
 * itself — proof the harness the chaos suite (S8-03) is built on is real.
 */
class MultiDeviceHarnessTest extends MultiDeviceTestCase
{
    public function test_each_connection_got_the_right_schema(): void
    {
        $this->onServer(function () {
            $this->assertTrue(Schema::hasTable('devices'));
            $this->assertTrue(Schema::hasTable('sync_log'));
            $this->assertFalse(Schema::hasTable('sync_changes'), 'sync_changes is local-only');
        });

        $this->onDeviceA(function () {
            $this->assertTrue(Schema::hasTable('sync_changes'));
            $this->assertTrue(Schema::hasTable('code_sequences'));
            $this->assertFalse(Schema::hasTable('devices'), 'devices is cloud-only');
        });
    }

    public function test_the_owner_and_three_devices_are_seeded(): void
    {
        $this->onServer(function () {
            $this->assertSame(1, DB::table('users')->count());
            $this->assertSame(3, DB::table('devices')->count());
            $this->assertSame(['DA1', 'DB1', 'DC1'], DB::table('devices')->orderBy('device_code')->pluck('device_code')->all());
        });
    }

    public function test_switching_context_genuinely_switches_which_database_eloquent_hits(): void
    {
        $this->onDeviceA(function () {
            Customer::create(['name' => 'Only On A', 'owner_user_id' => $this->ownerId]);
        });

        $this->onDeviceA(function () {
            $this->assertSame(1, Customer::count());
        });

        $this->onDeviceB(function () {
            $this->assertSame(0, Customer::count(), 'device_b must not see device_a\'s local-only data');
        });

        $this->onServer(function () {
            $this->assertSame(0, Customer::count(), 'a local create never reaches the server on its own — it needs a push');
        });
    }

    public function test_config_and_default_connection_are_restored_after_withcontext(): void
    {
        $before = config('database.default');

        $this->onDeviceA(fn () => null);

        $this->assertSame($before, config('database.default'));
        $this->assertSame($before, DB::getDefaultConnection());
    }

    public function test_a_full_push_round_trip_with_no_http(): void
    {
        $entityId = (string) Str::uuid7();

        $this->onDeviceA(function () use ($entityId) {
            $customer = new Customer(['name' => 'Round Trip Co', 'owner_user_id' => $this->ownerId]);
            $customer->setAttribute($customer->getKeyName(), $entityId);
            $customer->save();

            app(PushRunner::class, ['api' => $this->apiFor('device_a')])->run();
        });

        $this->onServer(function () use ($entityId) {
            $this->assertSame(1, Customer::count());
            $this->assertSame('Round Trip Co', Customer::find($entityId)?->name);
            $this->assertSame(1, DB::table('sync_log')->count());
        });

        // The outbox row is gone once the push is acknowledged — proof
        // this ran the real PushRunner/PushController/receipt path, not a
        // shortcut.
        $this->onDeviceA(function () {
            $this->assertSame(0, DB::table('sync_changes')->count());
        });
    }

    public function test_a_full_pull_round_trip_delivers_another_devices_change(): void
    {
        // A direct Eloquent create in cloud mode already goes through
        // Syncable's logSyncChange (invariant 5 — one path) — wrapping it
        // in SyncContext::withOriginDevice('DB1', ...) is exactly what
        // PushController itself does for a real push, so this is a
        // faithful stand-in for "device B pushed this," not a shortcut.
        // 'id' isn't in Customer's fillable list (§3.3's allowlist is the
        // wire fields only), so the real id is whatever HasUuids actually
        // generated — read it back rather than assuming a pre-picked one.
        $entityId = $this->onServer(fn () => SyncContext::withOriginDevice('DB1', fn () => Customer::create([
            'code' => 'CUST-DB1-000001',
            'name' => 'From B',
            'owner_user_id' => $this->ownerId,
        ])->id));

        $this->onDeviceA(function () use ($entityId) {
            $applied = app(PullRunner::class, ['api' => $this->apiFor('device_a')])->run();

            $this->assertSame(1, $applied);
            $this->assertSame('From B', Customer::find($entityId)?->name);
            $this->assertSame(0, DB::table('sync_changes')->count(), 'a pulled change must never queue its own outbox row');
        });
    }

    public function test_a_revoked_devices_push_throws_the_revoked_exception(): void
    {
        $this->onServer(function () {
            DB::table('devices')->where('device_code', 'DA1')->update(['revoked_at' => now()]);
        });

        $this->expectException(DeviceRevokedException::class);

        $this->apiFor('device_a')->push([]);
    }

    public function test_a_stale_client_schema_version_is_rejected(): void
    {
        config(['sync.min_supported_schema_version' => 5]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('schema_outdated');

            $this->apiFor('device_a', schemaVersion: 1)->handshake();
        } finally {
            config(['sync.min_supported_schema_version' => 1]);
        }
    }

    public function test_remote_applier_never_double_counts_across_connections(): void
    {
        // Sanity check that Sync::withoutRecording's static flag — process
        // -wide, not connection-scoped — behaves correctly when the
        // *connection* changes mid-callback, since RemoteApplier is what
        // every pull ultimately runs through.
        $this->onDeviceA(function () {
            app(RemoteApplier::class)->applyChange('customer', (string) Str::uuid7(), 'create', [
                'name' => 'Applied Directly',
                'owner_user_id' => $this->ownerId,
            ], 1);

            $this->assertSame(1, Customer::count());
            $this->assertSame(0, DB::table('sync_changes')->count());
        });
    }
}
