<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ProvisionDevice extends Command
{
    protected $signature = 'sync:device:provision {name} {device_code} {--owner= : Owning user\'s UUID}';

    protected $description = 'Register a new device and print its one-time provisioning code.';

    public function handle(): int
    {
        if (! sync_is_cloud()) {
            $this->error('Devices are provisioned on the server (APP_MODE=cloud), not a local install.');

            return self::FAILURE;
        }

        $deviceCode = strtoupper((string) $this->argument('device_code'));

        if (Device::where('device_code', $deviceCode)->exists()) {
            $this->error("Device code [{$deviceCode}] is already in use.");

            return self::FAILURE;
        }

        $code = strtoupper(Str::random(10));

        Device::create([
            'name' => $this->argument('name'),
            'device_code' => $deviceCode,
            'owner_user_id' => $this->option('owner'),
            'provisioning_code_hash' => Hash::make($code),
            'provisioning_code_expires_at' => now()->addDays(7),
        ]);

        $this->info("Device [{$deviceCode}] provisioned.");
        $this->line("One-time provisioning code (expires in 7 days, share with the device only): {$code}");

        return self::SUCCESS;
    }
}
