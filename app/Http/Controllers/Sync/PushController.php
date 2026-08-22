<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Models\Device;
use App\Sync\SyncableModel;
use App\Sync\SyncContext;
use App\Sync\SyncRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PushController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'changes' => ['required', 'array'],
            'changes.*.id' => ['required', 'uuid'],
            'changes.*.entity' => ['required', 'string'],
            'changes.*.entity_id' => ['required', 'uuid'],
            'changes.*.op' => ['required', 'in:create,update,delete'],
            'changes.*.base_version' => ['nullable', 'integer'],
            'changes.*.payload' => ['required', 'array'],
        ]);

        /** @var Device $device */
        $device = $request->attributes->get('device');

        $results = array_map(
            fn (array $change) => $this->processChange($change, $device),
            $data['changes'],
        );

        return response()->json(['results' => $results]);
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    protected function processChange(array $change, Device $device): array
    {
        // A4, checked first: a replayed change returns its original outcome
        // verbatim, never reprocessed — that's what makes a lost response
        // safe to retry instead of producing a phantom conflict (S07).
        if ($existing = DB::table('sync_receipts')->where('change_id', $change['id'])->first()) {
            return json_decode($existing->response_payload, true);
        }

        return DB::transaction(function () use ($change, $device) {
            $result = $this->applyChange($change, $device);

            DB::table('sync_receipts')->insert([
                'change_id' => $change['id'],
                'entity' => $change['entity'],
                'entity_id' => $change['entity_id'],
                'outcome' => $result['outcome'],
                'response_payload' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    protected function applyChange(array $change, Device $device): array
    {
        $result = ['id' => $change['id'], 'entity_id' => $change['entity_id']];

        if (! array_key_exists($change['entity'], config('sync.entities', []))) {
            return $result + ['outcome' => 'rejected', 'errors' => ['entity' => 'unknown entity']];
        }

        $modelClass = SyncRegistry::modelFor($change['entity']);

        // findIncludingTrashed()'s interface signature returns ?self (i.e.
        // ?SyncableModel) — a pure Model&SyncableModel intersection return
        // type isn't expressible there without reintroducing the same
        // static-vs-self friction findIncludingTrashed() itself hit. This
        // narrows back using what modelFor()'s own contract already
        // guarantees: every registered entity is a Model.
        /** @var (Model&SyncableModel)|null $row */
        $row = $modelClass::findIncludingTrashed($change['entity_id']);

        if ($change['op'] === 'create' && $row) {
            // Already applied (e.g. a retried push with no surviving receipt
            // — rare, but idempotent-safe rather than erroring).
            return $result + ['outcome' => 'applied', 'row' => $row->toWirePayload(), 'version' => $row->getVersion()];
        }

        if ($change['op'] !== 'create' && ! $row) {
            return $result + ['outcome' => 'rejected', 'errors' => ['entity_id' => 'no such record']];
        }

        if ($change['op'] !== 'create' && (int) $change['base_version'] !== $row->getVersion()) {
            return $this->recordConflict($change, $row, $device);
        }

        try {
            $validated = $change['op'] === 'delete'
                ? []
                : CustomerRequest::validatePayload($change['payload'], $change['op']);
        } catch (ValidationException $e) {
            return $result + ['outcome' => 'rejected', 'errors' => $e->errors()];
        }

        return SyncContext::withOriginDevice($change['origin_device'] ?? $device->device_code, function () use ($change, $modelClass, $row, $validated, $result) {
            if ($change['op'] === 'create') {
                /** @var Model&SyncableModel $model */
                $model = new $modelClass($validated);
                $model->setAttribute($model->getKeyName(), $change['entity_id']);
                $model->save();
            } elseif ($change['op'] === 'update') {
                $model = $row->fill($validated);
                $model->save();
            } else {
                $model = $row;
                $model->delete();
            }

            return $result + ['outcome' => 'applied', 'row' => $model->toWirePayload(), 'version' => $model->getVersion()];
        });
    }

    /**
     * §7 recommendation: detect and hold. Sprint 4's ConflictResolver picks
     * a winner (LWW + clock-skew guard); this just makes sure nothing is
     * silently overwritten or lost in the meantime.
     *
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    protected function recordConflict(array $change, Model&SyncableModel $row, Device $device): array
    {
        DB::table('sync_conflicts')->insert([
            'id' => (string) Str::uuid7(),
            'entity' => $change['entity'],
            'entity_id' => $change['entity_id'],
            'change_id' => $change['id'],
            'origin_device' => $change['origin_device'] ?? $device->device_code,
            'incoming_base_version' => $change['base_version'],
            'current_version' => $row->getVersion(),
            'incoming_payload' => json_encode($change['payload'], JSON_UNESCAPED_UNICODE),
            'current_payload' => json_encode($row->toWirePayload(), JSON_UNESCAPED_UNICODE),
            'status' => 'unresolved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $change['id'],
            'entity_id' => $change['entity_id'],
            'outcome' => 'conflict',
            'row' => $row->toWirePayload(), // A7 — client's next edit gets a current base_version
            'version' => $row->getVersion(),
        ];
    }
}
