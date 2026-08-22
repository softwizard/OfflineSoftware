<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\Device;
use App\Models\User;

class SnapshotControllerTest extends CloudTestCase
{
    private function authHeaders(Device $device): array
    {
        $token = $device->createToken('test')->plainTextToken;

        return ['Authorization' => "Bearer {$token}", 'X-Sync-Schema-Version' => (string) config('sync.schema_version')];
    }

    public function test_returns_only_the_devices_owner_scoped_rows(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $device = Device::factory()->create(['owner_user_id' => $owner->id]);

        Customer::factory(2)->create(['owner_user_id' => $owner->id]);
        Customer::factory()->create(['owner_user_id' => $otherOwner->id]);

        $response = $this->withHeaders($this->authHeaders($device))->getJson('/api/sync/snapshot');

        $response->assertOk();
        $this->assertCount(2, $response->json('entities.customer'));
        $this->assertIsInt($response->json('cursor'));
    }

    public function test_each_row_carries_its_id_and_version(): void
    {
        $owner = User::factory()->create();
        $device = Device::factory()->create(['owner_user_id' => $owner->id]);
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->withHeaders($this->authHeaders($device))->getJson('/api/sync/snapshot');

        $row = $response->json('entities.customer.0');
        $this->assertSame($customer->id, $row['id']);
        $this->assertSame($customer->version, $row['version']);
        $this->assertSame($customer->name, $row['name']);
        $this->assertArrayNotHasKey('deleted_at', $row); // not in the fields allowlist
    }

    public function test_soft_deleted_rows_are_excluded(): void
    {
        $owner = User::factory()->create();
        $device = Device::factory()->create(['owner_user_id' => $owner->id]);
        Customer::factory()->create(['owner_user_id' => $owner->id])->delete();

        $response = $this->withHeaders($this->authHeaders($device))->getJson('/api/sync/snapshot');

        $this->assertCount(0, $response->json('entities.customer'));
    }

    public function test_a_device_with_no_owner_gets_an_empty_snapshot(): void
    {
        $device = Device::factory()->create(['owner_user_id' => null]);

        $response = $this->withHeaders($this->authHeaders($device))->getJson('/api/sync/snapshot');

        $response->assertOk();
        $this->assertSame(['entities' => [], 'cursor' => 0], $response->json());
    }

    public function test_requires_device_authentication(): void
    {
        $this->getJson('/api/sync/snapshot')->assertStatus(401);
    }
}
