<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\Device;
use App\Models\User;
use App\Sync\SyncContext;
use Illuminate\Testing\TestResponse;

class PullControllerTest extends CloudTestCase
{
    private function pull(string $token, int $since = 0): TestResponse
    {
        return $this->withToken($token)
            ->withHeader('X-Sync-Schema-Version', (string) config('sync.schema_version'))
            ->getJson("/api/sync/pull?since={$since}");
    }

    public function test_pulling_from_zero_yields_every_in_scope_row_exactly_once(): void
    {
        $owner = User::factory()->create();
        $device = Device::factory()->create(['owner_user_id' => $owner->id]);
        $token = $device->createToken('test')->plainTextToken;

        $customers = Customer::factory(3)->create(['owner_user_id' => $owner->id]);

        $response = $this->pull($token);

        $response->assertOk();
        $this->assertCount(3, $response->json('changes'));

        $returnedIds = collect($response->json('changes'))->pluck('entity_id')->sort()->values()->all();
        $this->assertSame($customers->pluck('id')->sort()->values()->all(), $returnedIds);
    }

    public function test_excludes_the_pulling_devices_own_origin(): void
    {
        $owner = User::factory()->create();
        $device = Device::factory()->create(['owner_user_id' => $owner->id]);
        $token = $device->createToken('test')->plainTextToken;

        SyncContext::withOriginDevice($device->device_code, fn () => Customer::factory()->create(['owner_user_id' => $owner->id]));
        SyncContext::withOriginDevice('D99', fn () => Customer::factory()->create(['owner_user_id' => $owner->id]));
        Customer::factory()->create(['owner_user_id' => $owner->id]); // direct edit, no origin

        $response = $this->pull($token);

        $this->assertCount(2, $response->json('changes'));
    }

    public function test_only_returns_rows_in_the_devices_own_scope(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $deviceA = Device::factory()->create(['owner_user_id' => $ownerA->id]);
        $tokenA = $deviceA->createToken('test')->plainTextToken;

        Customer::factory()->create(['owner_user_id' => $ownerA->id]);
        Customer::factory(5)->create(['owner_user_id' => $ownerB->id]);

        $response = $this->pull($tokenA);

        $this->assertCount(1, $response->json('changes'));
    }

    public function test_cursor_advances_and_a_second_pull_from_it_yields_nothing_new(): void
    {
        $owner = User::factory()->create();
        $device = Device::factory()->create(['owner_user_id' => $owner->id]);
        $token = $device->createToken('test')->plainTextToken;

        Customer::factory(2)->create(['owner_user_id' => $owner->id]);

        $first = $this->pull($token);
        $cursor = $first->json('cursor');
        $this->assertGreaterThan(0, $cursor);

        $second = $this->pull($token, $cursor);

        $this->assertCount(0, $second->json('changes'));
        $this->assertSame($cursor, $second->json('cursor'));
    }

    public function test_a_device_with_no_owner_gets_an_empty_pull(): void
    {
        $device = Device::factory()->create(['owner_user_id' => null]);
        $token = $device->createToken('test')->plainTextToken;

        $owner = User::factory()->create();
        Customer::factory(3)->create(['owner_user_id' => $owner->id]);

        $response = $this->pull($token);

        $this->assertCount(0, $response->json('changes'));
    }

    public function test_rejects_unauthenticated_pull(): void
    {
        $this->getJson('/api/sync/pull?since=0')->assertStatus(401);
    }
}
