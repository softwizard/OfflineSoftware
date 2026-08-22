<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\Device;
use App\Models\User;
use App\Sync\SyncTime;
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

    public function test_update_with_a_stale_base_version_is_held_as_a_conflict_and_not_applied(): void
    {
        [$device, $owner, $token] = $this->authedDevice();
        $customer = Customer::create(['code' => 'CUST-TEST-000001', 'name' => 'Original', 'owner_user_id' => $owner->id]);

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

        $response->assertJsonPath('results.0.outcome', 'conflict');
        $response->assertJsonPath('results.0.row.name', 'Original');
        $this->assertSame('Original', $customer->fresh()->name);
        $this->assertSame(1, DB::table('sync_conflicts')->count());
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
}
