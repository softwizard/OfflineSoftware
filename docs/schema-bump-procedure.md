# Schema-bump procedure (S6-06)

How to change what a syncable entity's wire format looks like, without ever
breaking a laptop that hasn't updated yet. The rule from build-plan_1.md §3:
**additive-only, N-2 support** — the server must keep talking to any client
build from the last two schema versions, not just the latest one.

This project only has one schema version so far (`config/sync.php`'s
`schema_version` / `min_supported_schema_version`, both `1`, enforced by
`App\Http\Middleware\EnforceSchemaVersion`). This document is what to do
the first time that stops being true.

## The four steps, always in this order

A field rename is never one migration — it's four separate deploys, each
one safe to run with old clients still in the field.

### 1. Add

Add the new column alongside the old one. Add the new field to the
entity's `fields` allowlist in `config/sync.php` (`App\Sync\SyncRegistry`
reads this both directions — nothing outside the allowlist ever reaches
the wire, per §3.3). **Do not remove the old field yet.** Old clients keep
sending the old field; the server accepts both.

Bump `schema_version` in `config/sync.php`. Do **not** bump
`min_supported_schema_version` — that's what keeps N-2 clients working.

### 2. Backfill

One-time job/command to populate the new column from the old one for
existing rows. Runs once, on the server, against already-committed data —
it is not part of the sync protocol and must never itself go through
`App\Sync\LogWriter` (it isn't a client-originated change; re-emitting it
as one to every device would be a self-inflicted S09-scale flood, not a
schema migration).

### 3. Dual-write

`App\Http\Requests\CustomerRequest::validatePayload()` (or the equivalent
form request for whichever entity is changing) accepts **either** the old
field or the new one on write, and always populates both on the model
before save. `App\Sync\Syncable::fullCanonicalPayload()` — the canonical
row every push response, pull log entry, and snapshot row is built from —
keeps emitting both fields for as long as this step lasts. An N-2 client
still reads the old field name and is none the wiser; a client that has
picked up the new build reads the new one.

Stay in this state for at least as long as the N-2 window requires —
i.e., until every device still in the field has synced at least once on a
build that only needs the new field. `sync_log`'s `origin_device` plus a
fleet inventory (S6-05's dashboard, `last_schema_version` per device)
is how you confirm that, not a calendar guess.

### 4. Drop

Only once step 3's window has fully elapsed: remove the old field from
`fields`, stop dual-writing it in `fullCanonicalPayload()`, drop the
column in a migration, and raise `min_supported_schema_version` to
retire the pre-rename clients for good — they will now get `409
schema_outdated` (already-tested behavior, see
`tests/Feature/Sync/EnforceSchemaVersionTest.php`) instead of silently
corrupting data they can no longer parse correctly.

## What "additive-only" rules out

- Renaming or repurposing a column in place. Always add-then-drop, never
  edit-in-place — an in-place rename is indistinguishable, from an old
  client's perspective, from data corruption.
- Changing a field's type or semantics under the same name. That's a new
  field with a new name, going through all four steps, even if the old
  name would still technically fit.
- Tightening validation on an existing field (e.g. making a nullable
  field required) without a version gate — an N-2 client's existing push
  payloads must keep validating throughout the N-2 window.
- Skipping straight from "add" to "drop." The dual-write step is not
  optional scaffolding; it's the actual N-2 support guarantee. Without it,
  step 1's clients are already broken.

## Why LogWriter re-emits the full row, not a diff

`App\Sync\Syncable::logSyncChange()` always sends `fullCanonicalPayload()`
for create/update (A7's "canonical row" guarantee) — never a partial diff
of just what changed. That's what makes dual-write mechanically simple:
every log entry, every pull page, every push response, and every
`sync:seed` snapshot row is already the same one code path
(`fullCanonicalPayload()`), so adding a field there during step 3 covers
every one of those surfaces at once. There is no second place that could
be forgotten.
