<?php

namespace App\Http\Middleware;

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

        return $next($request);
    }
}
