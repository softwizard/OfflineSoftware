# Offline-First Customer System — Development Plan v3
### Everything decided so far, consolidated into a startable backlog

This supersedes the earlier design and spec documents. It is self-contained: a developer or AI coder should not need the previous files.

**Read §1–§3 before writing any code.** §3 closes the six gaps that made v2 un-startable — do not let a coder improvise those.

---

## §1 — LOCKED DECISIONS

### Stack

| Layer | Choice | Notes |
|---|---|---|
| Framework | Laravel 13.x, PHP 8.3+ | One codebase, `APP_MODE=local\|cloud` |
| UI | Livewire starter kit — Blade + Livewire + Tailwind + Flux | Localhost latency ≈ 0, so Livewire's weakness disappears |
| Laptop DB | SQLite, WAL mode, in `%LOCALAPPDATA%` | **Never** in Documents/Desktop — OneDrive corrupts it |
| Server DB | PostgreSQL 16+ | MySQL 8 acceptable if the team knows it better |
| Server cache/queue | Redis | |
| Device auth | Sanctum tokens on a `Device` model | Fortify for human login |
| Packaging | NativePHP desktop v2 → Windows only | Sprint 7, not before |
| Tests | Pest 4 | |
| CI/CD | GitHub Actions → GitHub Releases | Feeds the NativePHP auto-updater |

### Topology

Each employee runs their own local install on a Windows laptop. Data flows **both ways**. Concurrent offline edits of the same record are rare but must be handled without silent loss.

### The eight design corrections (carried from the v1 review)

| # | Rule |
|---|---|
| A1 | The pull sequence is allocated under a lock held to commit — **never** `AUTO_INCREMENT`, **never** `updated_at`. Otherwise clients permanently skip rows. |
| A2 | A change to a scope field (`owner_user_id`) **re-emits the full row** with a fresh seq, or devices never receive records newly assigned to them. |
| A3 | A record leaving a device's scope emits a `scope_revoke` event; the client hard-deletes it. |
| A4 | Push idempotency stores the **outcome**, not just the id. Replaying a lost response must return the original result, not a phantom conflict. |
| A5 | No Hybrid Logical Clock in v1. Detection is `base_version` comparison (exact, clock-free); resolution is LWW on `client_updated_at`, tiebroken on `origin_device`. |
| A6 | **No unique index on any user-entered field** in a syncable table. Uniqueness is a soft rule with a duplicate-review queue. |
| A7 | The push response returns the **full canonical row**, so the device's next edit carries a current `base_version`. |
| A8 | Clock-skew guard: a change whose `client_updated_at` deviates > 2 h from server `received_at` is flagged and loses ties. |

**Note on A8:** the 2 h threshold only catches gross skew. Two devices with, say, 10-minute drift between their clocks will resolve *every* true conflict toward whichever one runs fast — silently, with no flag. v1 deliberately doesn't fix this (that's what a Hybrid Logical Clock would buy, and A5 rules an HLC out for v1). Instead, S2-09's handshake echoes server time so each device can measure and log its own clock delta locally; surface that delta in `sync:status` (S1-10) so drift is auditable even though it isn't corrected.

---

## §2 — THE FIVE INVARIANTS

Restate these at the top of every coding session. They are the ones a coder silently violates, and each one causes data loss rather than a visible error.

1. **The outbox row is written inside the same DB transaction as the business write.** A crash between them loses the change with no trace.
2. **Remote applies never record to the outbox.** Every apply of server data goes through `Sync::withoutRecording()`, or the device pushes back what it just received, forever.
3. **The sequence is allocated last in the transaction, under a lock held to commit.** Any "optimisation" here reintroduces A1.
4. **The pull cursor is committed in the same transaction as the batch it describes.** A cursor written separately loses a batch on any crash.
5. **Every write to a syncable entity allocates its sequence through the same path**, whether it originates from a device push or a direct cloud-mode edit. Cloud-mode `Customer` writes (e.g. an admin editing a record straight on the server) must go through the same `Syncable`/`LogWriter` path as `POST /api/sync/push`, not a shortcut that writes the row and skips the allocator — two entry points into `sync_log` is two chances to violate invariant 3.

---

## §3 — CONTRACTS (implement exactly as written)

### 3.1 Identifiers — decide at Sprint 0, never retrofit

- All syncable tables use **UUIDv7** primary keys via Laravel's `HasUuids` (already v7 as of Laravel 12 — do not hand-roll, and do not use `Str::uuid()`, which is v4 and fragments indexes).
- **The `users` table must be converted to a UUID primary key in the very first migration**, before `devices`, `customers`, or anything else references it. Laravel's default is auto-increment; retrofitting this after Sprint 2 is a multi-day migration.
- Human-readable codes are device-prefixed: `CUST-D07-000123`. Never a globally sequential number generated locally.

### 3.2 Wire format — one contract, both directions

```json
{
  "id": "01997d3e-...",
  "entity": "customer",
  "entity_id": "01996b12-...",
  "op": "create",
  "base_version": 3,
  "client_updated_at": "2026-08-21T09:14:03.482Z",
  "origin_device": "D07",
  "payload": { "name": "Acme Ltd", "email": null }
}
```

Rules, enforced by a shared serializer used on both sides:

- **All timestamps UTC, ISO-8601, milliseconds, literal `Z`.** Never local time, never a bare offset. The laptop displays local; the wire is always UTC.
- **`null` means "set this to null". An absent key means "do not touch".** This distinction is what makes partial payloads safe — get it wrong and every update wipes unmentioned fields.
- Money as integer minor units. Never a float.
- Booleans as `true`/`false`, never `0`/`1`.
- **Receivers ignore unknown keys.** This is what lets a v4 server accept a change from a v3 laptop.

### 3.3 Entity registry — nothing is syncable without an entry

```php
// config/sync.php
'entities' => [
    'customer' => [
        'model'  => \App\Models\Customer::class,
        'table'  => 'customers',
        'scope'  => \App\Sync\Scopes\OwnerScope::class,
        'fields' => ['code','name','email','phone','city','notes','status','owner_user_id'],
    ],
],
```

`fields` is an **allowlist**, applied in both directions. Anything not listed is stripped from payloads. This single line prevents a whole class of bugs where `version`, `updated_at`, or an internal flag leaks into a payload and overwrites server state. `deleted_at` is deliberately never listed here — it's a sync-controlled field, not a user-entered one, and is allowed separately in every op (see §3.4).

`SyncRegistry` provides `keyForModel()`, `modelFor()`, `fields()`, `scopeFor()`.

`scope` is deliberately single-owner (`OwnerScope`) for v1 — every syncable entity has exactly one `owner_user_id`/device in scope at a time. If a future entity needs team- or multi-device scoping, `scope` becomes a class returning a *set* of devices, and A2/A3's re-emit/revoke logic must be re-checked against a set rather than a single value — don't bolt multi-owner behavior onto `OwnerScope` itself.

### 3.4 `recordSyncChange()` — the most important function in the system

```php
protected function recordSyncChange(string $op, array $attrs): void
{
    if (sync_is_cloud() || ! Sync::isRecording()) {
        return;                                    // invariant 2
    }

    $entity = SyncRegistry::keyForModel($this);

    // 'deleted_at' must be allowed on 'update' too, not just 'delete' — a restore
    // (SoftDeletes::restore() sets deleted_at back to null) fires an "updated" event.
    // Excluding it here means a restore's payload filters down to [], hits the
    // "nothing syncable changed" guard below, and the restore never reaches the outbox.
    $allow = $op === 'delete'
        ? ['deleted_at']
        : [...SyncRegistry::fields($entity), 'deleted_at'];

    $payload = collect($attrs)->only($allow)
        ->map(fn ($v) => $v instanceof \DateTimeInterface ? SyncTime::wire($v) : $v)
        ->all();

    if ($op === 'update' && $payload === []) {
        return;                                    // nothing syncable actually changed
    }

    // Participates in the ambient transaction — invariant 1.
    DB::table('sync_changes')->insert([
        'id'                => (string) Str::uuid7(),   // Ramsey\Uuid\Uuid::uuid7() if unavailable
        'entity'            => $entity,
        'entity_id'         => $this->getKey(),
        'op'                => $op,
        'payload'           => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'base_version'      => $op === 'create' ? null : $this->getOriginal('version'),
        'client_updated_at' => SyncTime::wire(now()),
        'origin_device'     => DeviceIdentity::id(),
        'status'            => 'pending',
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}
```

`getOriginal('version')` is the pre-save value — that is deliberately what `base_version` must carry.

There is no separate `restore` op. A restore is an `update` whose payload is exactly `{"deleted_at": null}` — the apply side (S3-06) must treat that as an un-delete, not as an ordinary field update.

### 3.5 Sequence allocator (server) — invariant 3

```php
// PostgreSQL — transaction-scoped advisory lock, released on commit
DB::statement('SELECT pg_advisory_xact_lock(?)', [8801]);
$next = DB::table('sync_sequence')->where('id', 1)->value('value') + 1;
DB::table('sync_sequence')->where('id', 1)->update(['value' => $next]);

// MySQL — row lock held to commit
$next = DB::table('sync_sequence')->where('id', 1)->lockForUpdate()->value('value') + 1;
DB::table('sync_sequence')->where('id', 1)->update(['value' => $next]);
```

Call this as the **last statement before commit**. Because the lock is held until commit, sequence order equals commit order and a reader can never observe a gap. It serialises the tail of every write — fine at this scale; move to a single-threaded stamping job only above a few hundred writes/second.

### 3.6 Device tokens

`Device` is its own model using `HasApiTokens`. Sanctum's `tokenable` is a morph, so `personal_access_tokens.tokenable_type` becomes `App\Models\Device` with no framework changes. Devices are **not** users, and a device token must never authenticate a web session.

### 3.7 Test harness context switching

```php
protected function withContext(array $overrides, callable $fn): mixed
{
    $prev = [];
    foreach ($overrides as $k => $v) { $prev[$k] = config($k); }

    config($overrides);
    DB::setDefaultConnection(config('database.default'));

    try {
        return $fn();
    } finally {
        config($prev);
        DB::setDefaultConnection(config('database.default'));
    }
}
```

Requires four named connections in `phpunit.xml` — `server`, `device_a`, `device_b`, `device_c` — each its own SQLite file, migrated in `setUp()`. `device_c` exists solely for S18's three-way conflict test; every other scenario uses just `server`/`device_a`/`device_b`. **Models must never hardcode `$connection`**, or the switch silently does nothing and every multi-device test passes against one database.

`SyncApi` is an **interface**. Production binds `HttpSyncApi`; tests bind `InProcessSyncApi`, which switches to the server context, invokes the controller directly, and switches back. No HTTP, no ports, deterministic in CI.

---

## §4 — SPRINT BACKLOG

67 tasks across 9 sprints. Each sprint ends with a demonstrable capability — if the demo cannot be shown, the sprint is not done regardless of how many tasks are ticked.

---

### SPRINT 0 — Foundations · 1 week
**Demo:** the app boots in both modes, migrates cleanly, CI is green.

| ID | Task | Done when |
|---|---|---|
| S0-01 | `laravel new` with the **Livewire starter kit** (Fortify auth); add Guzzle | Default Pest suite passes |
| S0-02 | **Convert `users` to a UUID primary key** — first migration, before anything references it | `User::first()->id` is a UUIDv7; no bigint FKs remain |
| S0-03 | `config/sync.php`, `sync_is_local()` / `sync_is_cloud()` helpers, `DeviceIdentity` | Local boot without `SYNC_DEVICE_ID` fails loudly |
| S0-04 | Split migrations into `shared/` `local/` `cloud/`, loaded by mode | Both modes `migrate:fresh` cleanly, different table sets |
| S0-05 | SQLite bootstrap: WAL, `foreign_keys=ON`, `busy_timeout=5000`, path resolver | `PRAGMA journal_mode` returns `wal` |
| S0-06 | Disable Fortify email verification, password reset and 2FA in local mode | Offline login works; no mail-dependent route reachable locally |
| S0-07 | Provision server: Postgres 16, Redis, HTTPS with a publicly-valid cert | Laptops outside the network can reach it over TLS |
| S0-08 | CI: Pest + Larastan on every push | Pipeline green on an empty commit |

---

### SPRINT 1 — Data model and outbox · 1 week
**Demo:** 40 offline edits to one customer coalesce into a single queued change. No server exists yet.

| ID | Task | Done when |
|---|---|---|
| S1-01 | `customers` migration per §1 — **no unique index on `email`** | A test asserts `email` has no unique index |
| S1-02 | `Customer` model: `HasUuids`, `SoftDeletes`, `version` guarded | `Customer::create()->id` is UUIDv7 |
| S1-03 | `SyncRegistry` + `config/sync.php` entities block (§3.3) | `SyncRegistry::fields('customer')` returns the allowlist |
| S1-04 | `SyncTime` serializer — UTC/ISO-8601/ms/`Z` both directions (§3.2) | Round-trip test across three timezones is lossless |
| S1-05 | `CodeGenerator` — `CUST-D07-000123` under a row lock | 100 concurrent creates give 100 distinct codes; D07 vs D08 never collide |
| S1-06 | `sync_changes` migration (local only) | Indexed on `(status, created_at)` and `(entity, entity_id, status)` |
| S1-07 | `Syncable` trait + `recordSyncChange()` **exactly as §3.4** | Create/update/delete each produce one correctly-shaped row |
| S1-08 | `Sync::withoutRecording()` guard (invariant 2) | Produces zero outbox rows; flag restored even when the callback throws |
| S1-09 | `ChangeCoalescer` — merge per entity; create+delete cancels to nothing | 40 edits → 1 change; create-then-delete → 0 changes |
| S1-10 | `php artisan sync:status` | Reports pending count, oldest age, cursor, failures |
| S1-11 | Factory + demo seeder | 25 customers seeded locally |

---

### SPRINT 2 — Server sync API · 1.5 weeks
**Demo:** push the same batch twice; responses are byte-identical and exactly one row lands.

| ID | Task | Done when |
|---|---|---|
| S2-01 | Migrations: `devices`, `sync_sequence`, `sync_log`, `sync_receipts`, `sync_conflicts` | `sync_sequence` seeded with exactly one row `(1, 0)` |
| S2-02 | `Device` model with `HasApiTokens` (§3.6); `POST /api/sync/register` | A provisioning code exchanges for a device token |
| S2-03 | `AuthenticateDevice` middleware — revocation, `last_seen_at` | Revoked device gets `401 device_revoked` |
| S2-04 | `EnforceSchemaVersion` middleware | v1 client against a v5 server gets `409 schema_outdated` |
| S2-05 | `SequenceAllocator` **exactly as §3.5**, with a docblock explaining the lock | 20 concurrent writes: no reader ever observes a gap |
| S2-06 | `LogWriter` — canonical snapshot, scope re-emit (A2), `scope_revoke` (A3) | Reassigning an owner emits one revoke and one full row, both fresh seqs |
| S2-07 | `POST /api/sync/push` — receipts first (A4), canonical row in response (A7) | Duplicate batch → identical response, one `sync_log` row |
| S2-08 | `GET /api/sync/pull` — scope filter + exclude own `origin_device` | Pulling from 0 yields every in-scope row exactly once |
| S2-09 | `HEAD /api/sync/ping` (no DB) and `POST /api/sync/handshake` (response includes server UTC time) | Ping answers under 50 ms with the database stopped; client can compute its clock delta from the handshake response |
| S2-10 | One shared `CustomerRequest` used by **both** the Livewire form and push | Server never rejects data the local UI accepted |

---

### SPRINT 3 — Client sync engine · 1.5 weeks
**Demo:** device A creates a customer offline; after sync, device B has it.

| ID | Task | Done when |
|---|---|---|
| S3-01 | `sync_state` and `sync_runs` migrations | Cursor and last-success persist |
| S3-02 | `SyncApi` interface + `HttpSyncApi` (headers, timeouts, retry) | Timeout and 409 paths covered by `Http::fake()` |
| S3-03 | `Connectivity` probe, 3 s timeout, 30 s cache | Never trusts the OS interface state |
| S3-04 | `PushRunner` — coalesce, batch, apply response, revert on transport failure | Mid-batch failure leaves everything pending; retry creates no duplicates |
| S3-05 | `PullRunner` — **cursor committed with the batch** (invariant 4) | 1,200 changes at limit 500 → 1,200 rows, correct cursor, 0 outbox rows |
| S3-06 | Apply logic: upsert, tombstone, restore (`update` with `deleted_at: null` → un-delete), `scope_revoke` → hard delete | Revoked record disappears locally; a restore reappears with prior data intact |
| S3-07 | `SyncRunner` orchestrator: probe → handshake → push → pull → log; `Cache::lock` | Two concurrent runs: exactly one executes |
| S3-08 | `php artisan sync:run` + scheduler entry (**not** a queued job) | Runs every 5 minutes; `sync_runs` fills |
| S3-09 | Backoff 1m → 5m → 15m → 1h | Failures back off, recover automatically |

---

### SPRINT 4 — Conflicts · 1 week
**Demo:** A and B edit the same customer while both offline; both converge to the same row and the loser is recoverable.

| ID | Task | Done when |
|---|---|---|
| S4-01 | `ConflictResolver`: detect on `base_version`; LWW; delete-beats-update; clock-skew guard (A8) | Both devices independently pick the same winner |
| S4-02 | `sync_conflicts` records both payloads | The losing value is fully recoverable |
| S4-03 | Admin review screen: side-by-side diff, clock-suspect flag, restore losing value | A restore propagates to all devices on next pull |
| S4-04 | Local notice: "3 of your changes were superseded" | The user is told, never silently overridden |
| S4-05 | Duplicate-candidate flagging for soft-unique fields (A6) | Two offline customers with the same email: both kept, both flagged |

---

### SPRINT 5 — Customer UI · 1 week
**Demo:** full CRUD with the network cable unplugged, and the status bar tells the truth.

| ID | Task | Done when |
|---|---|---|
| S5-01 | Livewire customer CRUD, **every write in `DB::transaction()`** | Each operation produces exactly one outbox row |
| S5-02 | Per-row badge: Synced / Pending (n) / Rejected | Reflects `sync_changes` accurately |
| S5-03 | Persistent status bar with `wire:poll` | Unplugging flips the badge within 30 s and recovers on its own |
| S5-04 | `/sync` page: queued changes, ages, attempts, errors, local conflicts | A user can see their 14 queued changes |
| S5-05 | "Sync Now" button | Dispatches a run; disabled while one is in flight |

---

### SPRINT 6 — Operational hardening · 1 week
**Demo:** a blank laptop is provisioned from a snapshot in under five minutes.

| ID | Task | Done when |
|---|---|---|
| S6-01 | `GET /api/sync/snapshot` (scoped, cached) + `sync:seed` | Seeded device matches the server exactly, no duplicates |
| S6-02 | Offline auth: local bcrypt hashes, cost ≥ 12 | Login works with no network |
| S6-03 | `max_offline_days` = 14 read-only lock, countdown from day 10 | Day 15 without sync blocks writes with a clear message |
| S6-04 | Revoke → `sync:wipe` local data | Revoked laptop holds no readable customer data after one cycle — **this requires the device to reconnect first; it is not a remote wipe.** A laptop that never reconnects still holds the data; BitLocker (§6 checklist) is the actual control for that case |
| S6-05 | Admin fleet dashboard: last seen, backlog, schema versions, unreviewed conflicts | A device silent for 3 days is visibly flagged |
| S6-06 | Written schema-bump procedure (additive-only, N-2 support) | A rename is documented as add → backfill → dual-write → drop |
| S6-07 | PostgreSQL backup: automated nightly dump/WAL archiving, restore procedure rehearsed on a scratch server | A restore from backup passes the S2-07/S2-08 push/pull smoke checks; RPO and RTO are written down |

---

### SPRINT 7 — Windows packaging · 1 week
**Demo:** a signed installer runs on a real corporate laptop and auto-updates itself.

| ID | Task | Done when |
|---|---|---|
| S7-01 | NativePHP desktop v2 install and `config/nativephp.php` | App launches as a desktop window |
| S7-02 | DB path resolver → `%LOCALAPPDATA%`, **asserting the path is not OneDrive-redirected** | A startup check refuses to run from a synced folder |
| S7-03 | Scheduler under NativePHP rather than Windows Task Scheduler | `sync:run` fires on schedule inside the packaged app |
| S7-04 | Microsoft **Artifact Signing** (~$10/mo, no hardware token) in CI | Installer is signed on every release build |
| S7-05 | GitHub Releases + updater config | A test release auto-installs on a client machine |
| S7-06 | With IT: AV exclusion for the app folder, long-path policy, BitLocker/SKU audit | Written confirmation from IT before pilot |
| S7-07 | Install on a **real corporate SOE image**, not a clean VM | Full CRUD + sync works on the actual fleet build |

---

### SPRINT 8 — Chaos suite and pilot · 1.5 weeks
**Demo:** all 20 scenarios green in CI, unattended, repeatably.

| ID | Task | Done when |
|---|---|---|
| S8-01 | Test harness `withContext()` + four connections (§3.7) | Device/server switching provably works |
| S8-02 | `InProcessSyncApi` test double | Full round trips run with no HTTP |
| S8-03 | The 20 scenarios in §5 | All green in CI |
| S8-04 | Two-week pilot with 3 real employees at a real low-signal site | Zero unexplained data loss |
| S8-05 | Review the conflict log; decide whether any table needs field-level merge | Decision recorded with evidence |

---

## §5 — CHAOS SCENARIO MATRIX

| # | Scenario | Expected |
|---|---|---|
| S01 | A creates 3 customers offline, syncs | All 3 on server; outbox empty |
| S02 | A syncs, then B syncs | B holds A's customers at identical `version` |
| S03 | A edits and syncs; B edits the same row and syncs | No conflict — B's `base_version` was current |
| S04 | A and B both edit the same row **while both offline** | One winner; **both converge**; 1 conflict row, both payloads recoverable |
| S05 | A deletes, B edits, both offline | Delete wins; conflict logged |
| S06 | Transport fails mid-batch | All changes still pending; retry creates no duplicates |
| S07 | Push succeeds, response lost, client retries | Receipt replayed; 1 log row; **no phantom conflict** |
| S08 | B's clock set 2 days back | `clock_suspect` set; existing wins; flagged for review |
| S09 | A offline 3 weeks with 500 changes | Syncs in batches; counts exact; cursor correct |
| S10 | A edits one customer 40 times offline | Coalescer emits 1 change with final values |
| S11 | Customer reassigned from A's scope to B's | A hard-deletes it; B receives the full row |
| S12 | Client v1 against server v5 | `409 schema_outdated`; nothing pushed; clear UI message |
| S13 | Local DB corrupted, re-seeded from snapshot | Matches server; no duplicates; no re-push of old changes |
| S14 | A and B both create a customer with the same email | Both accepted, both flagged; neither lost |
| S15 | Device revoked mid-session | 401 on next sync; local data wiped |
| S16 | Concurrent writes racing the allocator | No reader ever observes a seq gap (guards A1) |
| S17 | Admin restores a soft-deleted customer (`deleted_at` → `null`) | Restore reaches the outbox as an `update`; all devices un-delete the row with prior data intact |
| S18 | A, B, and C all edit the same row offline before any of them sync | Exactly one winner; all three converge; the conflict record captures all three payloads |
| S19 | Pull connection drops mid-batch, after some pages but not all | Cursor stays at the last fully-committed batch; retried pull is gap-free and produces no duplicates |
| S20 | Device stays offline past `max_offline_days` (14), then reconnects | Writes blocked locally from day 15 onward; reconnecting syncs the backlog with no loss and unlocks writes |

---

## §6 — WINDOWS ROLLOUT CHECKLIST

Agree these with IT **before** the pilot, not after.

- [ ] **OneDrive** — Known Folder Move confirmed *not* covering the app's data path. SQLite in WAL inside a synced folder will corrupt, and it will corrupt exactly the offline work this system exists to protect.
- [ ] **Antivirus** — Defender/corporate AV exclusion for the app folder. Bundled `php.exe` trips heuristics; per-write scanning makes SQLite crawl.
- [ ] **Long paths** — enabled by GPO, or the app installs to a short root. Electron plus nested `vendor/` exceeds `MAX_PATH`.
- [ ] **BitLocker — blocking gate, not informational.** Requires Windows Pro. Local data is unencrypted and, per S6-04, un-wipeable on a laptop that never reconnects — so audit the fleet SKU *before* the pilot, and any Home-SKU laptop must get SQLCipher budgeted before it's issued, not noted and deferred.
- [ ] **Server backups** — automated backup cadence and a rehearsed restore procedure exist before pilot (S6-07); RPO/RTO agreed with whoever owns the server, not assumed.
- [ ] **Deployment** — installer pushed via Intune/GPO with an AppLocker allow rule. Do not chase SmartScreen reputation; an internal fleet will never reach the download volume it needs.
- [ ] **Auto-update stays on** despite managed deployment — offline field staff are never on the corporate network, so Intune cannot reach them.

---

## §7 — WORKING AGREEMENT

**For an AI coder:**

- Paste §1–§3 plus **one sprint** per session. Pasting the whole backlog produces plausible code that fails S04 and S07.
- Require the test in the same commit as the code. The "Done when" column is the acceptance criterion.
- Re-state the five invariants (§2) at the top of every session.
- Spend disproportionate review time on **S1-07** (`recordSyncChange`) and **S8-01** (the harness). Everything downstream depends on them being right.

**Sequencing and effort** — roughly 10 weeks for one competent developer:

| Sprint | Focus | Weeks |
|---|---|---|
| 0–1 | Foundations, data model, outbox | 2 |
| 2–3 | Server API, client engine | 3 |
| 4–5 | Conflicts, UI | 2 |
| 6–7 | Ops hardening, Windows packaging | 2 |
| 8 | Chaos suite and pilot | 1.5 |

Treat any estimate under four weeks as a sign the chaos suite is being skipped.

**Day one, in order:** S0-01 → S0-02 → S0-03. Do not start S1 until `users` is on UUID keys — that migration gets exponentially more painful with every sprint that passes.

**Not in scope for v1, deliberately:** file attachments, multi-table FK ordering on push, field-level merge, encrypted local DB, multi-owner/team scope (§3.3 — scope is single-owner only), and any accumulative value (stock, balances). When invoices arrive, model quantities as append-only movement rows from day one — two offline `+5` and `−3` movements merge perfectly; two offline `quantity = 12` writes do not.
