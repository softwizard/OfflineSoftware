<?php

namespace Tests\Feature\Sync;

use App\Http\Controllers\Sync\PushController;
use App\Models\Customer;
use App\Models\Device;
use App\Models\User;
use App\Sync\SyncTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class PushControllerTest extends CloudTestCase
{
    /**
     * @return array{0: Device, 1: User, 2: string}
     */
    private function authedDevice(): array
    {
        $owner = User::factory()->create();
        $device = Device::factory()->create(['owner_user_id' => $owner->id]);
        $token = $device->createToken('test')->plainTextToken;

        return [$device, $owner, $token];
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function pushRequest(string $token, array $changes): TestResponse
    {
        return $this->withToken($token)
            ->withHeader('X-Sync-Schema-Version', (string) config('sync.schema_version'))
            ->postJson('/api/sync/push', ['changes' => $changes]);
    }

    private function createChange(Device $device, User $owner, ?string $entityId = null): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'entity' => 'customer',
            'entity_id' => $entityId ?? (string) Str::uuid7(),
            'op' => 'create',
            'base_version' => null,
            'client_updated_at' => SyncTime::wire(now()),
            'origin_device' => $device->device_code,
            'payload' => [
                'code' => 'CUST-'.$device->device_code.'-000001',
                'name' => 'Acme Ltd',
                'owner_user_id' => $owner->id,
            ],
        ];
    }

    public function test_push_create_applies_and_returns_the_canonical_row(): void
    {
        [$device, $owner, $token] = $this->authedDevice();

        $response = $this->pushRequest($token, [$this->createChange($device, $owner)]);

        $response->assertOk();
        $response->assertJsonPath('results.0.outcome', 'applied');
        $response->assertJsonPath('results.0.row.name', 'Acme Ltd');
        $response->assertJsonPath('results.0.version', 1);
        $this->assertSame(1, DB::table('sync_log')->count());
        $this->assertSame(1, DB::table('sync_receipts')->count());
    }

    public function test_duplicate_batch_returns_an_identical_response_and_writes_only_one_log_row(): void
    {
        [$device, $owner, $token] = $this->authedDevice();
        $changes = [$this->createChange($device, $owner)];

        $first = $this->pushRequest($token, $changes)->json();
        $second = $this->pushRequest($token, $changes)->json();

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('sync_log')->count());
        $this->assertSame(1, DB::table('sync_receipts')->count());
    }

    public function test_stale_base_version_where_current_is_newer_is_held_as_a_conflict(): void
    {
        // Sprint 2's "always hold" is gone — ConflictResolver actually
        // picks a winner now. This is the LWW-says-current-wins branch:
        // the row's last real edit is more recent than the incoming one.
        [$device, $owner, $token] = $this->authedDevice();
        $customer = Customer::create(['code' => 'CUST-TEST-000001', 'name' => 'Original', 'owner_user_id' => $owner->id]);
        DB::table('customers')->where('id', $customer->id)->update([
            'last_client_updated_at' => SyncTime::wire(now()),
            'last_origin_device' => 'D99',
        ]);

        $response = $this->pushRequest($token, [[
            'id' => (string) Str::uuid7(),
            'entity' => 'customer',
            'entity_id' => $customer->id,
            'op' => 'update',
            'base_version' => 999,
            'client_updated_at' => SyncTime::wire(now()->subHour()),
            'origin_device' => $device->device_code,
            'payload' => ['name' => 'Changed'],
        ]]);

        $response->assertJsonPath('results.0.outcome', 'conflict');
        $response->assertJsonPath('results.0.row.name', 'Original');
        $this->assertSame('Original', $customer->fresh()->name);
        $this->assertSame(1, DB::table('sync_conflicts')->count());
        $this->assertSame('current', DB::table('sync_conflicts')->value('winner'));
    }

    public function test_stale_base_version_where_incoming_is_newer_wins_and_applies(): void
    {
        [$device, $owner, $token] = $this->authedDevice();
        $customer = Customer::create(['code' => 'CUST-TEST-000001', 'name' => 'Original', 'owner_user_id' => $owner->id]);
        DB::table('customers')->where('id', $customer->id)->update([
            'last_client_updated_at' => SyncTime::wire(now()->subHour()),
            'last_origin_device' => 'D99',
        ]);

        $response = $this->pushRequest($token, [[
            'id' => (string) Str::uuid7(),
            'entity' => 'customer',
            'entity_id' => $customer->id,
            'op' => 'update',
            'base_version' => 999,
            'client_updated_at' => SyncTime::wire(now()),
            'origin_device' => $device->device_code,
            'payload' => ['name' => 'Changed'],
        ]]);

        $response->assertJsonPath('results.0.outcome', 'applied');
        $this->assertSame('Changed', $customer->fresh()->name);
        $this->assertSame(1, DB::table('sync_conflicts')->count());
        $this->assertSame('incoming', DB::table('sync_conflicts')->value('winner'));
    }

    public function test_update_with_the_correct_base_version_applies_and_bumps_version(): void
    {
        [$device, $owner, $token] = $this->authedDevice();
        $customer = Customer::create(['code' => 'CUST-TEST-000001', 'name' => 'Original', 'owner_user_id' => $owner->id]);

        $response = $this->pushRequest($token, [[
            'id' => (string) Str::uuid7(),
            'entity' => 'customer',
            'entity_id' => $customer->id,
            'op' => 'update',
            'base_version' => $customer->version,
            'client_updated_at' => SyncTime::wire(now()),
            'origin_device' => $device->device_code,
            'payload' => ['name' => 'Changed'],
        ]]);

        $response->assertJsonPath('results.0.outcome', 'applied');
        $this->assertSame('Changed', $customer->fresh()->name);
        $this->assertSame(2, $customer->fresh()->version);
    }

    public function test_update_of_a_nonexistent_record_is_rejected(): void
    {
        [$device, $owner, $token] = $this->authedDevice();

        $response = $this->pushRequest($token, [[
            'id' => (string) Str::uuid7(),
            'entity' => 'customer',
            'entity_id' => (string) Str::uuid7(),
            'op' => 'update',
            'base_version' => 1,
            'client_updated_at' => SyncTime::wire(now()),
            'origin_device' => $device->device_code,
            'payload' => ['name' => 'Changed'],
        ]]);

        $response->assertJsonPath('results.0.outcome', 'rejected');
    }

    public function test_invalid_payload_is_rejected_with_errors(): void
    {
        [$device, $owner, $token] = $this->authedDevice();

        $change = $this->createChange($device, $owner);
        $change['payload'] = ['owner_user_id' => $owner->id]; // no name, no code

        $response = $this->pushRequest($token, [$change]);

        $response->assertJsonPath('results.0.outcome', 'rejected');
        $this->assertArrayHasKey('name', $response->json('results.0.errors'));
    }

    public function test_rejects_unauthenticated_push(): void
    {
        $this->postJson('/api/sync/push', ['changes' => []])->assertStatus(401);
    }

    public function test_delete_applies_a_soft_delete(): void
    {
        [$device, $owner, $token] = $this->authedDevice();
        $customer = Customer::create(['code' => 'CUST-TEST-000001', 'name' => 'Original', 'owner_user_id' => $owner->id]);

        $response = $this->pushRequest($token, [[
            'id' => (string) Str::uuid7(),
            'entity' => 'customer',
            'entity_id' => $customer->id,
            'op' => 'delete',
            'base_version' => $customer->version,
            'client_updated_at' => SyncTime::wire(now()),
            'origin_device' => $device->device_code,
            'payload' => ['deleted_at' => SyncTime::wire(now())],
        ]]);

        $response->assertJsonPath('results.0.outcome', 'applied');
        $this->assertSoftDeleted($customer);
    }

    /**
     * F1 — the receipt guard (A4) and the receipt insert aren't atomic
     * with each other: two genuinely concurrent requests for the same
     * change_id can both miss the guard, both enter their own
     * transaction, and only one can win the sync_receipts primary key.
     * The loser's whole transaction rolls back (nothing is ever applied
     * twice) — but it must return the winner's stored outcome, not a 500.
     *
     * True concurrent-connection timing isn't reliably reproducible in a
     * single-process test, so this stands in for the race at exactly one
     * point — PushController::existingReceipt()'s *first* call (the
     * guard) is made to report a miss while a receipt search a moment
     * later (during recovery) will not — everything else is genuine,
     * unmocked behavior: the real customer insert this triggers, the
     * real sync_receipts unique-constraint violation, and the real
     * recovery query.
     */
    public function test_a_concurrent_duplicate_push_replays_the_receipt_instead_of_erroring(): void
    {
        [$device, $owner, $token] = $this->authedDevice();
        $change = $this->createChange($device, $owner);

        $winningResult = [
            'id' => $change['id'],
            'entity_id' => $change['entity_id'],
            'outcome' => 'applied',
            'row' => $change['payload'],
            'version' => 1,
        ];

        $controller = new class($winningResult) extends PushController
        {
            private bool $guardChecked = false;

            public function __construct(private array $winningResult) {}

            protected function existingReceipt(string $changeId): ?array
            {
                if (! $this->guardChecked) {
                    $this->guardChecked = true;

                    // The moment this request's own guard check misses,
                    // a genuinely concurrent request for the exact same
                    // change_id wins the race and commits first.
                    DB::table('sync_receipts')->insert([
                        'change_id' => $changeId,
                        'entity' => 'customer',
                        'entity_id' => $this->winningResult['entity_id'],
                        'outcome' => 'applied',
                        'response_payload' => json_encode($this->winningResult, JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return null;
                }

                return parent::existingReceipt($changeId);
            }
        };

        $device->forceFill(['last_seen_at' => now()])->save();
        $request = Request::create('/api/sync/push', 'POST', ['changes' => [$change]]);
        $request->attributes->set('device', $device);

        $response = $controller->store($request);

        $this->assertSame($winningResult, $response->getData(true)['results'][0]);
        $this->assertSame(1, DB::table('sync_receipts')->count(), 'the losing transaction must not leave a second receipt behind');
        $this->assertSame(0, Customer::count(), 'the losing transaction rolled back in full — nothing was applied twice');
    }

    public function test_a_unique_constraint_violation_unrelated_to_the_receipt_race_still_throws(): void
    {
        [$device, $owner, $token] = $this->authedDevice();
        Customer::create(['code' => 'CUST-DUP-000001', 'name' => 'Existing', 'owner_user_id' => $owner->id]);

        $change = $this->createChange($device, $owner);
        $change['payload']['code'] = 'CUST-DUP-000001'; // collides with the row above, not with any receipt

        $request = Request::create('/api/sync/push', 'POST', ['changes' => [$change]]);
        $request->attributes->set('device', $device);

        $this->expectException(UniqueConstraintViolationException::class);

        app(PushController::class)->store($request);
    }
}
