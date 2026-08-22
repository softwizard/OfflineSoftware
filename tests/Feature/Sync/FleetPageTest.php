<?php

namespace Tests\Feature\Sync;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FleetPageTest extends CloudTestCase
{
    public function test_requires_authentication(): void
    {
        $this->get(route('sync.fleet'))->assertRedirect(route('login'));
    }

    public function test_lists_devices_with_owner_and_last_seen(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $owner = User::factory()->create(['email' => 'field-rep@example.com']);
        Device::factory()->create(['name' => 'Sam\'s Laptop', 'device_code' => 'D07', 'owner_user_id' => $owner->id, 'last_seen_at' => now()]);

        $this->get(route('sync.fleet'))
            ->assertOk()
            ->assertSee("Sam's Laptop")
            ->assertSee('D07')
            ->assertSee('field-rep@example.com');
    }

    public function test_flags_a_device_silent_for_more_than_3_days(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        Device::factory()->create(['name' => 'Stale Laptop', 'last_seen_at' => now()->subDays(4)]);
        Device::factory()->create(['name' => 'Fresh Laptop', 'last_seen_at' => now()->subHours(1)]);

        $response = $this->get(route('sync.fleet'))->assertOk();

        $response->assertSeeInOrder(['Stale Laptop', 'Silent 3+ days']);
        $response->assertSee('Fresh Laptop');
    }

    public function test_shows_backlog_count_from_the_sync_log(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $owner = User::factory()->create();
        $device = Device::factory()->create(['device_code' => 'D01', 'owner_user_id' => $owner->id, 'last_pulled_seq' => 0]);

        DB::table('sync_sequence')->where('id', 1)->update(['value' => 3]);

        for ($seq = 1; $seq <= 3; $seq++) {
            DB::table('sync_log')->insert([
                'seq' => $seq,
                'entity' => 'customer',
                'entity_id' => (string) Str::uuid7(),
                'event' => 'change',
                'op' => 'create',
                'payload' => json_encode(['name' => "Row {$seq}"]),
                'target_owner_user_id' => $owner->id,
                'version' => 1,
                'origin_device' => 'D99', // some other device — counts toward D01's backlog
                'server_received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->get(route('sync.fleet'))->assertOk()->assertSee('3');

        $this->assertSame('D01', $device->device_code); // sanity: the device this backlog belongs to
    }

    public function test_shows_the_unreviewed_conflicts_count(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        DB::table('sync_conflicts')->insert([
            'id' => (string) Str::uuid7(),
            'entity' => 'customer',
            'entity_id' => (string) Str::uuid7(),
            'change_id' => (string) Str::uuid7(),
            'origin_device' => 'D07',
            'incoming_base_version' => 1,
            'current_version' => 1,
            'incoming_payload' => json_encode(['name' => 'A']),
            'current_payload' => json_encode(['name' => 'B']),
            'winner' => 'current',
            'clock_suspect' => false,
            'incoming_op' => 'update',
            'current_was_deleted' => false,
            'status' => 'resolved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('sync.fleet'))->assertOk()->assertSee('1 unreviewed conflict');
    }

    public function test_flags_a_revoked_device(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        Device::factory()->revoked()->create(['name' => 'Ex Laptop']);

        $this->get(route('sync.fleet'))->assertOk()->assertSeeInOrder(['Ex Laptop', 'Revoked']);
    }
}
