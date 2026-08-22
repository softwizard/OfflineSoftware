<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DuplicateDetectorTest extends CloudTestCase
{
    public function test_two_customers_with_the_same_email_are_both_kept_and_both_flagged(): void
    {
        $owner = User::factory()->create();

        $a = Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => 'dup@example.com']);
        $b = Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => 'dup@example.com']);

        $this->assertSame(2, Customer::count(), 'A6 — no unique index; neither create is rejected');

        $flags = DB::table('duplicate_candidates')->where('field', 'email')->get();
        $this->assertCount(2, $flags);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $flags->pluck('entity_id')->all());
        $this->assertTrue($flags->every(fn ($row) => $row->value === 'dup@example.com'));
        $this->assertTrue($flags->every(fn ($row) => $row->status === 'unresolved'));
    }

    public function test_distinct_emails_are_not_flagged(): void
    {
        $owner = User::factory()->create();

        Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => 'a@example.com']);
        Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => 'b@example.com']);

        $this->assertSame(0, DB::table('duplicate_candidates')->count());
    }

    public function test_a_null_email_is_never_flagged(): void
    {
        $owner = User::factory()->create();

        Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => null]);
        Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => null]);

        $this->assertSame(0, DB::table('duplicate_candidates')->count());
    }

    public function test_a_soft_deleted_customer_does_not_count_toward_a_collision(): void
    {
        $owner = User::factory()->create();

        $a = Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => 'dup@example.com']);
        $a->delete();
        Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => 'dup@example.com']);

        $this->assertSame(0, DB::table('duplicate_candidates')->count());
    }

    public function test_re_saving_an_already_flagged_customer_does_not_duplicate_the_flag(): void
    {
        $owner = User::factory()->create();

        $a = Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => 'dup@example.com']);
        Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => 'dup@example.com']);

        $a->update(['city' => 'Springfield']); // unrelated field touched, re-triggers the check

        $this->assertSame(2, DB::table('duplicate_candidates')->where('field', 'email')->count());
    }

    public function test_a_third_matching_customer_flags_all_three(): void
    {
        $owner = User::factory()->create();

        Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => 'dup@example.com']);
        Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => 'dup@example.com']);
        Customer::factory()->create(['owner_user_id' => $owner->id, 'email' => 'dup@example.com']);

        $this->assertSame(3, DB::table('duplicate_candidates')->where('field', 'email')->count());
    }
}
