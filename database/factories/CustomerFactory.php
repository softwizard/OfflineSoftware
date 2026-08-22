<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $attributes = [
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'city' => fake()->city(),
            'notes' => null,
            'status' => 'active',
            'owner_user_id' => User::factory(),
        ];

        // Local mode's Customer::booted() generates one via DeviceIdentity;
        // cloud mode has no device to generate one from, so a direct create
        // (not through push, where the client already supplied it) needs
        // the factory to provide it explicitly.
        if (sync_is_cloud()) {
            $attributes['code'] = 'CUST-TEST-'.fake()->unique()->numerify('######');
        }

        return $attributes;
    }
}
