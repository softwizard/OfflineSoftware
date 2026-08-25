<?php

use App\Models\Customer;
use App\Sync\Scopes\OwnerScope;

return [

    /*
    |--------------------------------------------------------------------------
    | Sync Mode
    |--------------------------------------------------------------------------
    |
    | 'local'  — a laptop install. Talks to the server over the sync API and
    |             keeps an outbox. Requires SYNC_DEVICE_ID (see DeviceIdentity).
    | 'cloud'  — the server install. Never records to an outbox; it is the
    |             canonical store other devices pull from.
    |
    | F3 — deliberately no default. A server that boots in local mode
    | keeps an outbox instead of writing the canonical sync_log, and every
    | device would silently stop receiving changes — the failure mode for
    | a missing/wrong APP_MODE must be loud and immediate
    | (SyncServiceProvider::boot()), not a quiet fallback to 'local'.
    |
    */

    'mode' => env('APP_MODE'),

    /*
    |--------------------------------------------------------------------------
    | Device Identity
    |--------------------------------------------------------------------------
    |
    | Set only in local mode. Used for the device-prefixed human codes
    | (CUST-D07-000123), the wire format's origin_device, and pull's
    | exclude-own-origin filter. See build-plan_1.md §3.1 / §3.2.
    |
    */

    'device_id' => env('SYNC_DEVICE_ID'),

    /*
    |--------------------------------------------------------------------------
    | Server Connection
    |--------------------------------------------------------------------------
    |
    | Set only in local mode. device_token is issued by POST /api/sync/register
    | (see App\Console\Commands\SyncRegister) and stored back into .env —
    | there's no database column for it, matching how SYNC_DEVICE_ID itself
    | is bootstrapped.
    |
    */

    'server_url' => env('SYNC_SERVER_URL'),
    'device_token' => env('SYNC_DEVICE_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Schema Version
    |--------------------------------------------------------------------------
    |
    | Sent by every local client as X-Sync-Schema-Version; the server
    | rejects a client older than min_supported_schema_version with
    | 409 schema_outdated (App\Http\Middleware\EnforceSchemaVersion). Only
    | version 1 exists so far — S6-06's schema-bump procedure (additive
    | -only, N-2 support) is what will ever widen the gap between these two.
    |
    */

    'schema_version' => 1,
    'min_supported_schema_version' => 1,

    /*
    |--------------------------------------------------------------------------
    | Entity Registry
    |--------------------------------------------------------------------------
    |
    | Nothing is syncable without an entry here. 'fields' is an allowlist
    | applied in both directions — see build-plan_1.md §3.3. Populated
    | starting Sprint 1.
    |
    */

    'entities' => [
        'customer' => [
            'model' => Customer::class,
            'table' => 'customers',
            'scope' => OwnerScope::class,
            'fields' => ['code', 'name', 'email', 'phone', 'city', 'notes', 'status', 'owner_user_id'],
            // A6 — no unique index on these; flagged for review instead
            // (DuplicateDetector) when two records collide. 'code' is
            // deliberately excluded: it's system-generated, not
            // user-entered, and does carry a real unique index (§3.1).
            'soft_unique' => ['email'],
        ],
    ],

];
