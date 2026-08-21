<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_25_customers_locally(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(25, Customer::count());
    }

    public function test_seeding_produces_no_outbox_rows(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, DB::table('sync_changes')->count());
    }
}
