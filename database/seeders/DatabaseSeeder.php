<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\User;
use App\Sync\Sync;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Deliberately not the WithoutModelEvents trait — it would also
     * suppress Customer's 'creating' event, which is what generates each
     * customer's code (App\Models\Customer::booted()). Sync::withoutRecording()
     * is the purpose-built tool here: it stops Syncable from queuing outbox
     * rows for seeded data (bootstrap state, not a device's own pending
     * change — the same idea as a device freshly provisioned from a
     * snapshot, S6-01) without touching any other model event.
     */
    public function run(): void
    {
        Sync::withoutRecording(function () {
            $owner = User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

            Customer::factory(25)->create(['owner_user_id' => $owner->id]);
        });
    }
}
