<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSchemaVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientVersion = (int) $request->header('X-Sync-Schema-Version', '0');
        $minSupported = (int) config('sync.min_supported_schema_version');

        if ($clientVersion < $minSupported) {
            return response()->json(['error' => 'schema_outdated'], 409);
        }

        // S6-05's fleet dashboard reads this back — runs after the 'device'
        // middleware in every route group that uses both, so the device is
        // already resolved onto the request.
        /** @var Device|null $device */
        $device = $request->attributes->get('device');
        $device?->forceFill(['last_schema_version' => $clientVersion])->save();

        return $next($request);
    }
}
