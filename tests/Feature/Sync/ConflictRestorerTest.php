<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use App\Sync\ConflictRestorer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConflictRestorerTest extends CloudTestCase
{
    public function test_restoring_a_losing_update_reapplies_its_field_values(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Server Wins']);

        $conflictId = (string) Str::uuid7();
        DB::table('sync_conflicts')->insert([
            'id' => $conflictId,
            'entity' => 'customer',
            'entity_id' => $customer->id,
            'change_id' => (string) Str::uuid7(),
            'origin_device' => 'D07',
            'incoming_base_version' => 1,
            'current_version' => $customer->version,
            'incoming_payload' => json_encode(['name' => 'Client Edit']),
            'current_payload' => json_encode(['name' => 'Server Wins']),
            'winner' => 'current',
            'clock_suspect' => false,
            'incoming_op' => 'update',
            'current_was_deleted' => false,
            'status' => 'resolved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(ConflictRestorer::class)->restore(DB::table('sync_conflicts')->where('id', $conflictId)->first());

        $this->assertSame('Client Edit', $customer->fresh()->name);
        $this->assertSame('restored', DB::table('sync_conflicts')->where('id', $conflictId)->value('status'));
    }

    public function test_restoring_a_losing_delete_deletes_the_currently_live_row(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id]);

        $conflictId = (string) Str::uuid7();
        DB::table('sync_conflicts')->insert([
            'id' => $conflictId,
            'entity' => 'customer',
            'entity_id' => $customer->id,
            'change_id' => (string) Str::uuid7(),
            'origin_device' => 'D07',
            'incoming_base_version' => 1,
            'current_version' => $customer->version,
            'incoming_payload' => json_encode(['deleted_at' => now()->toIso8601String()]),
            'current_payload' => json_encode($customer->toWirePayload()),
            'winner' => 'current', // the update "won", the delete lost
            'clock_suspect' => false,
            'incoming_op' => 'delete',
            'current_was_deleted' => false,
            'status' => 'resolved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(ConflictRestorer::class)->restore(DB::table('sync_conflicts')->where('id', $conflictId)->first());

        $this->assertSoftDeleted($customer);
    }

    public function test_restoring_a_losing_value_that_was_trashed_un_deletes_it(): void
    {
        // An incoming delete beat a current update (delete-beats-update) —
        // the row is now trashed. Restoring the LOSING (current) side
        // means bringing it back with its pre-delete field values.
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Was live']);
        $customer->delete();

        $conflictId = (string) Str::uuid7();
        DB::table('sync_conflicts')->insert([
            'id' => $conflictId,
            'entity' => 'customer',
            'entity_id' => $customer->id,
            'change_id' => (string) Str::uuid7(),
            'origin_device' => 'D07',
            'incoming_base_version' => 1,
            'current_version' => $customer->version,
            'incoming_payload' => json_encode(['deleted_at' => now()->toIso8601String()]),
            'current_payload' => json_encode(['name' => 'Was live']),
            'winner' => 'incoming',
            'clock_suspect' => false,
            'incoming_op' => 'delete',
            'current_was_deleted' => false, // before the delete, the current side wasn't trashed
            'status' => 'resolved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(ConflictRestorer::class)->restore(DB::table('sync_conflicts')->where('id', $conflictId)->first());

        $customer->refresh();
        $this->assertNull($customer->deleted_at);
        $this->assertSame('Was live', $customer->name);
    }

    public function test_restoring_produces_a_sync_log_entry_so_it_propagates_on_pull(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Server Wins']);
        $seqBefore = (int) (DB::table('sync_log')->max('seq') ?? 0);

        $conflictId = (string) Str::uuid7();
        DB::table('sync_conflicts')->insert([
            'id' => $conflictId,
            'entity' => 'customer',
            'entity_id' => $customer->id,
            'change_id' => (string) Str::uuid7(),
            'origin_device' => 'D07',
            'incoming_base_version' => 1,
            'current_version' => $customer->version,
            'incoming_payload' => json_encode(['name' => 'Client Edit']),
            'current_payload' => json_encode(['name' => 'Server Wins']),
            'winner' => 'current',
            'clock_suspect' => false,
            'incoming_op' => 'update',
            'current_was_deleted' => false,
            'status' => 'resolved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(ConflictRestorer::class)->restore(DB::table('sync_conflicts')->where('id', $conflictId)->first());

        $newRows = DB::table('sync_log')->where('entity_id', $customer->id)->where('seq', '>', $seqBefore)->get();
        $this->assertCount(1, $newRows);
        $this->assertSame('Client Edit', json_decode($newRows->first()->payload, true)['name']);
    }
}
