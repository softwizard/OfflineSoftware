<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * §3.6 — a device token must never authenticate a web session. This
 * resolves the bearer token directly via PersonalAccessToken::findToken(),
 * not through Laravel's Auth facade / auth:sanctum guard, so there's no
 * shared guard configuration a web request could ever go through.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $accessToken = $token ? PersonalAccessToken::findToken($token) : null;
        $device = $accessToken?->tokenable instanceof Device ? $accessToken->tokenable : null;

        if (! $device) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        if ($device->revoked_at !== null) {
            return response()->json(['error' => 'device_revoked'], 401);
        }

        $device->forceFill(['last_seen_at' => now()])->save();

        $request->attributes->set('device', $device);

        return $next($request);
    }
}
