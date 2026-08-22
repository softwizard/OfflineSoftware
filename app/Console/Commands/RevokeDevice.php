<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;

/**
 * S6-04. Deliberately does NOT delete the device's Sanctum token — a
 * revoked device still needs to authenticate exactly one more request to
 * learn it's revoked at all (App\Http\Middleware\AuthenticateDevice checks
 * revoked_at after resolving the token, returning the specific
 * 'device_revoked' error the client acts on). Deleting the token instead
 * would just make every request fail with a generic 401, and the device
 * would sit there retrying forever with no way to distinguish "revoked"
 * from "network still down."
 */
class RevokeDevice extends Command
{
    protected $signature = 'sync:device:revoke {device_code}';

    protected $description = 'Revoke a device — its next sync attempt will be told to wipe its local data.';

    public function handle(): int
    {
        if (! sync_is_cloud()) {
            $this->error('Devices are revoked on the server (APP_MODE=cloud), not a local install.');

            return self::FAILURE;
        }

        $deviceCode = strtoupper((string) $this->argument('device_code'));
        $device = Device::where('device_code', $deviceCode)->first();

        if (! $device) {
            $this->error("No device with code [{$deviceCode}].");

            return self::FAILURE;
        }

        if ($device->revoked_at !== null) {
            $this->line("Device [{$deviceCode}] is already revoked.");

            return self::SUCCESS;
        }

        $device->forceFill(['revoked_at' => now()])->save();

        $this->info("Device [{$deviceCode}] revoked. It will wipe its local data on its next sync attempt.");

        return self::SUCCESS;
    }
}
