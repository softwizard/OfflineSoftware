<?php

namespace Tests\Feature\Sync;

use App\Livewire\Sync\SupersededNotice;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SupersededNoticeTest extends TestCase
{
    use RefreshDatabase;

    private function makeConflictRow(): string
    {
        $owner = User::factory()->create();
        $customer = Customer::create(['name' => 'Acme', 'owner_user_id' => $owner->id]);
        DB::table('sync_changes')->where('entity_id', $customer->id)->delete();

        DB::table('sync_changes')->insert([
            'id' => (string) Str::uuid7(),
            'entity' => 'customer',
            'entity_id' => $customer->id,
            'op' => 'update',
            'payload' => json_encode(['name' => 'Changed']),
            'base_version' => 1,
            'client_updated_at' => now()->toIso8601String(),
            'origin_device' => 'D01',
            'status' => 'conflict',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $customer->id;
    }

    public function test_shows_nothing_when_there_are_no_superseded_changes(): void
    {
        Livewire::test(SupersededNotice::class)
            ->assertDontSee('of your changes');
    }

    public function test_shows_a_count_of_unacknowledged_conflicts(): void
    {
        $this->makeConflictRow();

        Livewire::test(SupersededNotice::class)
            ->assertSee('1 of your changes was superseded');
    }

    public function test_pluralizes_for_multiple_conflicts(): void
    {
        $this->makeConflictRow();
        $this->makeConflictRow();
        $this->makeConflictRow();

        Livewire::test(SupersededNotice::class)
            ->assertSee('3 of your changes were superseded');
    }

    public function test_acknowledging_clears_the_notice(): void
    {
        $this->makeConflictRow();

        Livewire::test(SupersededNotice::class)
            ->assertSee('of your changes')
            ->call('acknowledge')
            ->assertDontSee('of your changes');

        $this->assertSame(0, DB::table('sync_changes')->where('status', 'conflict')->whereNull('acknowledged_at')->count());
    }

    public function test_does_not_show_already_acknowledged_conflicts(): void
    {
        $this->makeConflictRow();
        DB::table('sync_changes')->where('status', 'conflict')->update(['acknowledged_at' => now()]);

        Livewire::test(SupersededNotice::class)
            ->assertDontSee('of your changes');
    }
}
