<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

class ConflictsPageTest extends CloudTestCase
{
    public function test_lists_recorded_conflicts(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id]);

        DB::table('sync_conflicts')->insert([
            'id' => (string) Str::uuid7(),
            'entity' => 'customer',
            'entity_id' => $customer->id,
            'change_id' => (string) Str::uuid7(),
            'origin_device' => 'D07',
            'incoming_base_version' => 1,
            'current_version' => 1,
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

        $this->get(route('sync.conflicts'))
            ->assertOk()
            ->assertSee('Client Edit')
            ->assertSee('Server Wins')
            ->assertSee('D07');
    }

    public function test_restore_action_applies_the_losing_value(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Server Wins']);

        $conflictId = (string) Str::uuid7();
        DB::table('sync_conflicts')->insert([
            'id' => $conflictId,
            'entity' => 'customer',
            'entity_id' => $customer->id,
            'change_id' => (string) Str::uuid7(),
            'origin_device' => 'D07',
            'incoming_base_version' => 1,
            'current_version' => 1,
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

        Livewire::test('pages::sync.conflicts')
            ->call('restore', $conflictId)
            ->assertOk();

        $this->assertSame('Client Edit', $customer->fresh()->name);
        $this->assertSame('restored', DB::table('sync_conflicts')->where('id', $conflictId)->value('status'));
    }

    public function test_requires_authentication(): void
    {
        $this->get(route('sync.conflicts'))->assertRedirect(route('login'));
    }
}
