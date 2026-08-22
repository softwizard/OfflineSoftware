<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Models\Device;
use App\Sync\ConflictResolver;
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
            'changes.*.client_updated_at' => ['required', 'string'],
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

        $originDevice = $change['origin_device'] ?? $device->device_code;

        if ($change['op'] !== 'create' && (int) $change['base_version'] !== $row->getVersion()) {
            return $this->resolveConflict($change, $modelClass, $row, $originDevice);
        }

        try {
            $validated = $change['op'] === 'delete'
                ? []
                : CustomerRequest::validatePayload($change['payload'], $change['op']);
        } catch (ValidationException $e) {
            return $result + ['outcome' => 'rejected', 'errors' => $e->errors()];
        }

        return $this->applyValidated($change, $modelClass, $row, $validated, $originDevice);
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  class-string<Model&SyncableModel>  $modelClass
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function applyValidated(array $change, string $modelClass, ?Model $existingRow, array $validated, string $originDevice): array
    {
        return SyncContext::withOriginDevice($originDevice, fn () => SyncContext::withClientUpdatedAt(
            $change['client_updated_at'],
            function () use ($change, $modelClass, $existingRow, $validated) {
                if ($change['op'] === 'create') {
                    /** @var Model&SyncableModel $model */
                    $model = new $modelClass($validated);
                    $model->setAttribute($model->getKeyName(), $change['entity_id']);
                    $model->save();
                } elseif ($change['op'] === 'update') {
                    /** @var Model&SyncableModel $model */
                    $model = $existingRow;
                    $model->fill($validated);
                    $model->save();
                } else {
                    /** @var Model&SyncableModel $model */
                    $model = $existingRow;
                    $model->delete();
                }

                return [
                    'id' => $change['id'],
                    'entity_id' => $change['entity_id'],
                    'outcome' => 'applied',
                    'row' => $model->toWirePayload(),
                    'version' => $model->getVersion(),
                ];
            },
        ));
    }

    /**
     * S4-01/S4-02 — ConflictResolver always picks a winner (LWW, delete
     * -beats-update, A8 skew guard); this records both payloads either
     * way and, only when the incoming side actually wins, applies it —
     * the "detect and hold" placeholder from Sprint 2 is gone.
     *
     * @param  array<string, mixed>  $change
     * @param  class-string<Model&SyncableModel>  $modelClass
     * @return array<string, mixed>
     */
    protected function resolveConflict(array $change, string $modelClass, Model&SyncableModel $row, string $originDevice): array
    {
        $resolution = app(ConflictResolver::class)->resolve(
            $change['op'],
            $change['client_updated_at'],
            $originDevice,
            (bool) $row->trashed(),
            $row->getLastClientUpdatedAt(),
            $row->getLastOriginDevice(),
            now(),
        );

        DB::table('sync_conflicts')->insert([
            'id' => (string) Str::uuid7(),
            'entity' => $change['entity'],
            'entity_id' => $change['entity_id'],
            'change_id' => $change['id'],
            'origin_device' => $originDevice,
            'incoming_base_version' => $change['base_version'],
            'current_version' => $row->getVersion(),
            'incoming_payload' => json_encode($change['payload'], JSON_UNESCAPED_UNICODE),
            'current_payload' => json_encode($row->toWirePayload(), JSON_UNESCAPED_UNICODE),
            'winner' => $resolution['winner'],
            'clock_suspect' => $resolution['clock_suspect'],
            'incoming_op' => $change['op'],
            'current_was_deleted' => $row->trashed(),
            'status' => 'resolved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($resolution['winner'] === 'current') {
            return [
                'id' => $change['id'],
                'entity_id' => $change['entity_id'],
                'outcome' => 'conflict',
                'row' => $row->toWirePayload(), // A7 — client's next edit gets a current base_version
                'version' => $row->getVersion(),
            ];
        }

        try {
            $validated = $change['op'] === 'delete'
                ? []
                : CustomerRequest::validatePayload($change['payload'], $change['op']);
        } catch (ValidationException $e) {
            // Won the resolution but failed validation — a genuine edge
            // case; reject rather than apply bad data just because the
            // timestamp said so.
            return [
                'id' => $change['id'],
                'entity_id' => $change['entity_id'],
                'outcome' => 'rejected',
                'errors' => $e->errors(),
            ];
        }

        return $this->applyValidated($change, $modelClass, $row, $validated, $originDevice);
    }
}
