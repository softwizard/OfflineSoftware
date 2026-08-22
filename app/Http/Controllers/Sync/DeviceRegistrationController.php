<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DeviceRegistrationController extends Controller
{
    /**
     * Exchanges a one-time provisioning code (from `sync:device:provision`)
     * for a Sanctum device token. The code is consumed on success — it
     * can't be replayed even if leaked after the fact.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_code' => ['required', 'string'],
            'provisioning_code' => ['required', 'string'],
        ]);

        $device = Device::where('device_code', $data['device_code'])->first();

        if (! $this->codeIsValid($device, $data['provisioning_code'])) {
            return response()->json(['error' => 'invalid_provisioning_code'], 422);
        }

        $token = $device->createToken('device:'.$device->device_code)->plainTextToken;

        $device->forceFill([
            'provisioning_code_hash' => null,
            'provisioning_code_expires_at' => null,
            'last_seen_at' => now(),
        ])->save();

        return response()->json([
            'device_token' => $token,
            'device_code' => $device->device_code,
            'schema_version' => config('sync.schema_version'),
        ], 201);
    }

    private function codeIsValid(?Device $device, string $submittedCode): bool
    {
        if (! $device || ! $device->provisioning_code_hash) {
            return false;
        }

        if ($device->provisioning_code_expires_at?->isPast()) {
            return false;
        }

        return Hash::check($submittedCode, $device->provisioning_code_hash);
    }
}
