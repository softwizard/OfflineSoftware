<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PullController extends Controller
{
    protected const MAX_LIMIT = 1000;

    public function index(Request $request): JsonResponse
    {
        $since = (int) $request->query('since', 0);
        $limit = min((int) $request->query('limit', 500), self::MAX_LIMIT);

        /** @var Device $device */
        $device = $request->attributes->get('device');

        if (! $device->owner_user_id) {
            return response()->json(['changes' => [], 'cursor' => $since]);
        }

        $rows = DB::table('sync_log')
            ->where('target_owner_user_id', $device->owner_user_id)
            ->where('seq', '>', $since)
            ->where(function ($query) use ($device) {
                // Exclude the device's own origin — it already has the
                // change it just pushed, no need to hand it straight back.
                $query->whereNull('origin_device')->orWhere('origin_device', '!=', $device->device_code);
            })
            ->orderBy('seq')
            ->limit($limit)
            ->get();

        $changes = $rows->map(fn ($row) => [
            'seq' => $row->seq,
            'entity' => $row->entity,
            'entity_id' => $row->entity_id,
            'event' => $row->event,
            'op' => $row->op,
            'payload' => $row->payload ? json_decode($row->payload, true) : null,
            'version' => $row->version,
        ])->all();

        return response()->json([
            'changes' => $changes,
            'cursor' => $rows->isNotEmpty() ? (int) $rows->last()->seq : $since,
        ]);
    }
}
