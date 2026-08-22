<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SyncStatusPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->get(route('sync.status'))->assertRedirect(route('login'));
    }

    public function test_shows_14_queued_changes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Customer::factory(14)->create(['owner_user_id' => $user->id]);

        $this->assertSame(14, DB::table('sync_changes')->count());
        $this->get(route('sync.status'))->assertOk();
        $rendered = Livewire::test('pages::sync.status');
        $this->assertCount(14, $rendered->viewData('changes'));
    }

    public function test_shows_age_attempts_status_and_error(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create(['owner_user_id' => $user->id]);
        DB::table('sync_changes')->where('entity_id', $customer->id)->update([
            'status' => 'rejected',
            'attempts' => 3,
            'error' => json_encode(['name' => ['The name field is required.']]),
        ]);

        $this->get(route('sync.status'))
            ->assertOk()
            ->assertSee('3')
            ->assertSee('Rejected')
            ->assertSee('name field is required');
    }

    public function test_acknowledge_marks_the_row_acknowledged(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create(['owner_user_id' => $user->id]);
        $changeId = DB::table('sync_changes')->where('entity_id', $customer->id)->value('id');
        DB::table('sync_changes')->where('id', $changeId)->update(['status' => 'conflict']);

        Livewire::test('pages::sync.status')->call('acknowledge', $changeId);

        $this->assertNotNull(DB::table('sync_changes')->where('id', $changeId)->value('acknowledged_at'));
    }

    public function test_shows_nothing_queued_when_the_outbox_is_empty(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('sync.status'))->assertOk()->assertSee('Nothing queued');
    }
}
