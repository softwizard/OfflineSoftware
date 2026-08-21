<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use App\Sync\Sync;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WithoutRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_inside_the_callback_produce_zero_outbox_rows(): void
    {
        $owner = User::factory()->create();

        Sync::withoutRecording(function () use ($owner) {
            $customer = Customer::create(['name' => 'Acme', 'owner_user_id' => $owner->id]);
            $customer->update(['name' => 'Renamed']);
            $customer->delete();
        });

        $this->assertSame(0, DB::table('sync_changes')->count());
    }

    public function test_recording_flag_is_restored_after_the_callback(): void
    {
        Sync::withoutRecording(fn () => null);

        $this->assertTrue(Sync::isRecording());
    }

    public function test_recording_flag_is_restored_even_when_the_callback_throws(): void
    {
        $this->assertTrue(Sync::isRecording());

        try {
            Sync::withoutRecording(function () {
                throw new Exception('boom');
            });
        } catch (Exception) {
            // expected
        }

        $this->assertTrue(Sync::isRecording());
    }

    public function test_writes_after_a_throwing_callback_are_recorded_normally(): void
    {
        $owner = User::factory()->create();

        try {
            Sync::withoutRecording(function () {
                throw new Exception('boom');
            });
        } catch (Exception) {
            // expected
        }

        Customer::create(['name' => 'Acme', 'owner_user_id' => $owner->id]);

        $this->assertSame(1, DB::table('sync_changes')->count());
    }
}
