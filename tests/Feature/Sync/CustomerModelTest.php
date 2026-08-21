<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_id_is_a_uuid_v7(): void
    {
        $customer = Customer::factory()->create();

        $this->assertTrue(Str::isUuid($customer->id));
        $this->assertSame(7, (int) $customer->id[14]);
    }

    public function test_customer_gets_a_device_prefixed_code_on_create(): void
    {
        $customer = Customer::factory()->create();

        $this->assertMatchesRegularExpression('/^CUST-D01-\d{6}$/', $customer->code);
    }

    public function test_version_is_not_mass_assignable(): void
    {
        $owner = User::factory()->create();

        $customer = Customer::create([
            'name' => 'Acme',
            'owner_user_id' => $owner->id,
            'version' => 999,
        ]);

        $this->assertSame(1, $customer->version);
    }

    public function test_soft_deletes_are_enabled(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();

        $this->assertSoftDeleted($customer);
    }
}
