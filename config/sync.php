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
    */

    'mode' => env('APP_MODE', 'local'),

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
        ],
    ],

];
