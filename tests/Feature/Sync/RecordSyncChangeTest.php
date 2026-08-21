<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecordSyncChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_produces_one_row_with_a_null_base_version(): void
    {
        $owner = User::factory()->create();

        $customer = Customer::create(['name' => 'Acme', 'owner_user_id' => $owner->id]);

        $rows = DB::table('sync_changes')->where('entity_id', $customer->id)->get();

        $this->assertCount(1, $rows);
        $row = $rows->first();

        $this->assertSame('customer', $row->entity);
        $this->assertSame('create', $row->op);
        $this->assertNull($row->base_version);
        $this->assertSame('D01', $row->origin_device);
        $this->assertSame('pending', $row->status);

        $payload = json_decode($row->payload, true);
        $this->assertSame('Acme', $payload['name']);
        $this->assertArrayHasKey('code', $payload);
        $this->assertArrayNotHasKey('version', $payload, 'version is not in the entity allowlist and must be stripped.');
    }

    public function test_update_produces_one_row_carrying_the_pre_save_version_as_base_version(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id]);
        DB::table('sync_changes')->delete();

        $customer->update(['name' => 'Renamed']);

        $rows = DB::table('sync_changes')->where('entity_id', $customer->id)->get();
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('update', $row->op);
        $this->assertSame(1, $row->base_version);

        $payload = json_decode($row->payload, true);
        $this->assertSame(['name' => 'Renamed'], $payload);
    }

    public function test_update_with_no_syncable_field_changed_records_nothing(): void
    {
        $customer = Customer::factory()->create();
        DB::table('sync_changes')->delete();

        // Touching only a non-allowlisted attribute (updated_at via touch())
        // must not produce an outbox row.
        $customer->touch();

        $this->assertSame(0, DB::table('sync_changes')->where('entity_id', $customer->id)->count());
    }

    public function test_delete_produces_one_row_with_only_deleted_at(): void
    {
        $customer = Customer::factory()->create();
        DB::table('sync_changes')->delete();

        $customer->delete();

        $rows = DB::table('sync_changes')->where('entity_id', $customer->id)->get();
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('delete', $row->op);

        $payload = json_decode($row->payload, true);
        $this->assertSame(['deleted_at'], array_keys($payload));
    }

    public function test_restore_reaches_the_outbox_as_an_update_with_deleted_at_null(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();
        DB::table('sync_changes')->delete();

        $customer->restore();

        $rows = DB::table('sync_changes')->where('entity_id', $customer->id)->get();
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('update', $row->op);

        $payload = json_decode($row->payload, true);
        $this->assertSame(['deleted_at' => null], $payload);
    }
}
