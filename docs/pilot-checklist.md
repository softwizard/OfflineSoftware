# Sprint 8 — chaos suite: done; pilot: needs you

build-plan_1.md's Sprint 8 has five tasks. Three are code — done, tested,
and green in this repo. The last two need three real employees, two real
weeks, and a real low-signal site, none of which an AI coding agent can
stand in for.

## Done (S8-01 – S8-03)

| Task | What's actually in the repo |
|---|---|
| S8-01 | `tests/Feature/Sync/MultiDeviceTestCase.php` — four genuinely separate named SQLite connections (`config/database.php`: `server`, `device_a`, `device_b`, `device_c`), migrated fresh every test method. `withContext()` implemented exactly per §3.7. Proven in `MultiDeviceHarnessTest.php`: context switching, connection isolation, and config/connection restoration all directly asserted. |
| S8-02 | `tests/Feature/Sync/InProcessSyncApi.php` — implements `SyncApi`, invokes the controllers directly (no HTTP, no ports), replicating `AuthenticateDevice`'s revocation check and `EnforceSchemaVersion`'s version check itself since bypassing the HTTP layer bypasses that real middleware too. Includes fault injection (`failNextPush()`, `failPullAfterPages()`) for the transport-failure scenarios. Full round trips proven in `MultiDeviceHarnessTest.php`. |
| S8-03 | All 20 scenarios from §5, in `tests/Feature/Sync/ChaosScenariosTest.php`, all green. |

### A real bug the chaos suite actually found

S05 ("A deletes, B edits, both offline") failed on the first real run —
not a test bug, a production one: `Illuminate\Database\Eloquent\
SoftDeletes::runSoftDelete()` persists `deleted_at` through a direct
query-builder `update()` call, **never through `save()`** — so a cloud
-side delete fired `deleting`/`deleted` but never `updating`/`updated`,
and `App\Sync\Syncable`'s version-bump logic lived exclusively in the
`updating` hook. A customer delete silently left `version` completely
unchanged, which meant a concurrently-pushed update's `base_version`
check had nothing to catch — it would have applied right over a delete
with no conflict ever recorded, on a real server, silently.

Fixed in `Syncable::bootSyncable()`'s `deleting` hook (bumps version and
persists it via `saveQuietly()`, since `runSoftDelete()`'s own update
only ever touches `deleted_at`/`updated_at` and won't pick up other
dirty attributes). Regression-tested directly in
`tests/Feature/Sync/SyncableVersionTest.php`, independent of the chaos
scenario that found it — this was a real gap in every sprint's testing
before Sprint 8, not something a unit test happened to already cover.

329 tests (312 passed, 17 skipped, 0 failed), Pint clean, Larastan clean.

---

## Needs you (S8-04 – S8-05)

### S8-04 — two-week pilot, 3 real employees, a real low-signal site

**Blocked on:** actual people, actual time, an actual place with bad
connectivity. This is the one task in the entire build plan that is
*definitionally* not something to simulate — its whole point is
surfacing what six sprints of chaos scenarios and manual verification
against real infrastructure still can't: real user behavior, a real
laptop's real quirks, a real place where the network really drops.

Before it starts:

- [ ] Sprint 7's blockers are cleared — a signed, installed build exists
      (`docs/windows-packaging-checklist.md`'s S7-04/S7-05), or the pilot
      runs against a dev build with that understood as a limitation.
- [ ] The 3 pilot laptops passed S7-07's real-SOE-image checklist, not
      just a clean VM.
- [ ] `sync:device:provision` has issued each pilot laptop its own
      device token, and each is confirmed reachable
      (`sync:run` completes a real cycle) before day one.
- [ ] Someone owns watching `sync_runs` / the fleet dashboard
      (`/sync/fleet`, Sprint 6) daily during the two weeks — the pilot is
      only worth running if someone's actually looking at what it
      surfaces while it's live, not reviewing it after the fact.

During it — this is where "zero unexplained data loss" (the plan's
literal "done when") gets decided, and it can only be decided by
watching the real thing:

- [ ] Every customer record any pilot user believes they created or
      edited is traceable to a `sync_changes`/`sync_log` row somewhere —
      "unexplained" specifically means a user says data is missing and
      no log entry anywhere accounts for it, not merely a slow sync.
- [ ] Note every real occurrence of a chaos scenario happening
      *organically* (a real dropped connection mid-push, a real
      conflict from two people editing the same customer) — these are
      worth more than the simulated ones, precisely because nothing
      about them was staged.

### S8-05 — review the conflict log; decide on field-level merge

**Blocked on:** S8-04's real conflict data existing at all — there is
nothing to review yet, and reviewing the *simulated* conflicts this
sprint's chaos suite produced would answer a different question (does
the mechanism work) than the one this task asks (does whole-row LWW
actually fit how real users edit records).

Once the pilot has real conflict rows in `sync_conflicts`:

1. Pull every row where `status = 'resolved'` from the pilot window.
   `/sync/conflicts` (Sprint 4) is built for exactly this.
2. For each one, ask: did whole-row last-write-wins throw away a change
   a *different field* than the one that "lost" would have kept? E.g.
   A edits `phone`, B edits `notes` on the same record, offline, at
   close to the same time — LWW picks one edit's entire row and the
   other person's real, non-conflicting edit vanishes, even though nothing
   about those two edits actually collided.
3. Count how often that specific shape shows up, not just conflict
   count in general — a high raw conflict count with zero field-level
   collisions among them says LWW is fine; a handful of conflicts that
   are *all* this shape says it isn't, regardless of the total.
4. Record the decision — keep whole-row LWW, or scope field-level merge
   to specific columns — **with the evidence**, i.e. the actual
   conflict rows that drove it, not just the conclusion. Future-you (or
   whoever picks this up) needs to be able to see *why*, not just *what
   was decided*.
