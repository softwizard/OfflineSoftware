<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChangeCoalescerTest extends TestCase
{
    use RefreshDatabase;

    public function test_40_edits_coalesce_into_1_change_with_final_values(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::create(['name' => 'v0', 'owner_user_id' => $owner->id]);

        for ($i = 1; $i <= 40; $i++) {
            $customer->update(['name' => "v{$i}"]);
        }

        $rows = DB::table('sync_changes')->where('entity_id', $customer->id)->get();
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('create', $row->op, 'the batch started with create, so it should still read as create');
        $this->assertNull($row->base_version);

        $payload = json_decode($row->payload, true);
        $this->assertSame('v40', $payload['name']);
    }

    public function test_create_then_delete_cancels_to_zero_changes(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::create(['name' => 'Temp', 'owner_user_id' => $owner->id]);
        $customer->delete();

        $this->assertSame(0, DB::table('sync_changes')->where('entity_id', $customer->id)->count());
    }

    public function test_updates_then_delete_on_an_already_synced_record_collapses_to_one_delete(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id]);
        DB::table('sync_changes')->delete(); // pretend it was already pushed

        $customer->update(['name' => 'Renamed once']);
        $customer->update(['name' => 'Renamed twice']);
        $customer->delete();

        $rows = DB::table('sync_changes')->where('entity_id', $customer->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('delete', $rows->first()->op);
    }

    public function test_unrelated_customers_are_not_coalesced_together(): void
    {
        $owner = User::factory()->create();
        $a = Customer::create(['name' => 'A', 'owner_user_id' => $owner->id]);
        $b = Customer::create(['name' => 'B', 'owner_user_id' => $owner->id]);

        $this->assertSame(2, DB::table('sync_changes')->count());
        $this->assertSame(1, DB::table('sync_changes')->where('entity_id', $a->id)->count());
        $this->assertSame(1, DB::table('sync_changes')->where('entity_id', $b->id)->count());
    }
}
