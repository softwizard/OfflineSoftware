<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Sync\SyncTime;
use Illuminate\Http\JsonResponse;

class HandshakeController extends Controller
{
    /**
     * Echoes server time so each device can measure and log its own clock
     * delta locally (see build-plan_1.md's note on A8: the 2h skew guard
     * only catches gross drift, this is what makes smaller drift auditable).
     */
    public function store(): JsonResponse
    {
        return response()->json([
            'server_time' => SyncTime::wire(now()),
            'schema_version' => config('sync.schema_version'),
        ]);
    }
}
