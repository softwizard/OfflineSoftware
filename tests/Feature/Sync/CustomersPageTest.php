<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CustomersPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->get(route('customers.index'))->assertRedirect(route('login'));
    }

    public function test_lists_customers(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Customer::factory()->create(['owner_user_id' => $user->id, 'name' => 'Acme Ltd']);

        $this->get(route('customers.index'))->assertOk()->assertSee('Acme Ltd');
    }

    public function test_creating_a_customer_produces_exactly_one_outbox_row(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::customers.index')
            ->set('name', 'New Customer')
            ->set('email', 'new@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'New Customer')->first();
        $this->assertNotNull($customer);
        $this->assertSame($user->id, $customer->owner_user_id);
        $this->assertSame(1, DB::table('sync_changes')->where('entity_id', $customer->id)->count());
    }

    public function test_creating_without_a_name_fails_validation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::customers.index')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    }

    public function test_editing_a_customer_produces_exactly_one_outbox_row(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create(['owner_user_id' => $user->id, 'name' => 'Old Name']);
        DB::table('sync_changes')->delete(); // clear the create's own row

        Livewire::test('pages::customers.index')
            ->call('edit', $customer->id)
            ->set('name', 'New Name')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New Name', $customer->fresh()->name);
        $this->assertSame(1, DB::table('sync_changes')->where('entity_id', $customer->id)->count());
    }

    public function test_deleting_a_customer_produces_exactly_one_outbox_row(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create(['owner_user_id' => $user->id]);
        DB::table('sync_changes')->delete();

        Livewire::test('pages::customers.index')->call('delete', $customer->id);

        $this->assertSoftDeleted($customer);
        $this->assertSame(1, DB::table('sync_changes')->where('entity_id', $customer->id)->count());
    }

    public function test_badge_shows_synced_when_there_is_no_outbox_row(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create(['owner_user_id' => $user->id]);
        DB::table('sync_changes')->delete();

        $this->get(route('customers.index'))->assertOk()->assertSee('Synced');
    }

    public function test_badge_shows_pending_count_for_a_queued_change(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Customer::factory()->create(['owner_user_id' => $user->id]); // create leaves 1 pending row

        $this->get(route('customers.index'))->assertOk()->assertSee('Pending (1)');
    }

    public function test_badge_shows_conflict_for_a_conflicted_change(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create(['owner_user_id' => $user->id]);
        DB::table('sync_changes')->where('entity_id', $customer->id)->update(['status' => 'conflict']);

        $this->get(route('customers.index'))->assertOk()->assertSee('Conflict');
    }
}
