<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word().' '.fake()->word().' laptop',
            'device_code' => 'D'.str_pad((string) fake()->unique()->numberBetween(1, 999), 2, '0', STR_PAD_LEFT),
            'owner_user_id' => User::factory(),
            'last_seen_at' => null,
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }

    public function withProvisioningCode(string $code = 'TESTCODE123'): static
    {
        return $this->state([
            'provisioning_code_hash' => Hash::make($code),
            'provisioning_code_expires_at' => now()->addDays(7),
        ]);
    }
}
