<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use App\Sync\RemoteApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RemoteApplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_applying_a_new_entity_id_upserts_a_new_local_row(): void
    {
        $owner = User::factory()->create();
        $id = (string) Str::uuid7();

        app(RemoteApplier::class)->applyChange('customer', $id, 'create', [
            'code' => 'CUST-D07-000001',
            'name' => 'Acme Ltd',
            'owner_user_id' => $owner->id,
        ], 1);

        $customer = Customer::find($id);
        $this->assertNotNull($customer);
        $this->assertSame('Acme Ltd', $customer->name);
        $this->assertSame(1, $customer->version);
    }

    public function test_applying_an_update_upserts_the_existing_row_and_sets_version(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Old Name']);

        app(RemoteApplier::class)->applyChange('customer', $customer->id, 'update', [
            'name' => 'New Name',
        ], 5);

        $customer->refresh();
        $this->assertSame('New Name', $customer->name);
        $this->assertSame(5, $customer->version);
    }

    public function test_applying_does_not_queue_an_outbox_row(): void
    {
        $owner = User::factory()->create();
        $id = (string) Str::uuid7();

        app(RemoteApplier::class)->applyChange('customer', $id, 'create', [
            'code' => 'CUST-D07-000001',
            'name' => 'Acme Ltd',
            'owner_user_id' => $owner->id,
        ], 1);

        $this->assertSame(0, DB::table('sync_changes')->count());
    }

    public function test_delete_tombstones_the_local_row(): void
    {
        $customer = Customer::factory()->create();

        app(RemoteApplier::class)->applyChange('customer', $customer->id, 'delete', null, null);

        $this->assertSoftDeleted($customer);
    }

    public function test_a_non_delete_op_restores_a_trashed_local_row(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id]);
        $customer->delete();
        $this->assertSoftDeleted($customer);

        app(RemoteApplier::class)->applyChange('customer', $customer->id, 'update', [
            'name' => 'Restored Co',
        ], 3);

        $customer->refresh();
        $this->assertNull($customer->deleted_at);
        $this->assertSame('Restored Co', $customer->name);
    }

    public function test_a_stale_intermediate_entry_does_not_regress_an_already_newer_local_row(): void
    {
        // Found live against real MySQL, not in a unit test first: a
        // device's own winning conflict resolution is excluded from its
        // own next pull (own-origin filter), but an earlier, now
        // -superseded entry from the OTHER device can still appear in the
        // same pull page. Applying blindly in seq order would regress
        // this device's already-correct state back to the losing value.
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Edited by B']);
        DB::table('customers')->where('id', $customer->id)->update(['version' => 3]);

        // A stale log entry (version 2) arrives after this device's local
        // state is already at version 3 (from its own push response).
        app(RemoteApplier::class)->applyChange('customer', $customer->id, 'update', [
            'name' => 'Edited by A',
        ], 2);

        $customer->refresh();
        $this->assertSame('Edited by B', $customer->name, 'a stale entry must not overwrite already-newer local state');
        $this->assertSame(3, $customer->version);
    }

    public function test_an_entry_at_exactly_the_local_version_is_not_reapplied(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Current']);

        app(RemoteApplier::class)->applyChange('customer', $customer->id, 'update', [
            'name' => 'Redundant Redelivery',
        ], $customer->version);

        $this->assertSame('Current', $customer->fresh()->name);
    }

    public function test_a_genuinely_newer_entry_still_applies_normally(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Old']);

        app(RemoteApplier::class)->applyChange('customer', $customer->id, 'update', [
            'name' => 'New',
        ], $customer->version + 1);

        $this->assertSame('New', $customer->fresh()->name);
    }

    public function test_scope_revoke_hard_deletes_the_local_row(): void
    {
        $customer = Customer::factory()->create();

        app(RemoteApplier::class)->applyScopeRevoke('customer', $customer->id);

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        // Not even soft-deleted — a plain find (with trashed) finds nothing.
        $this->assertNull(Customer::withTrashed()->find($customer->id));
    }
}
