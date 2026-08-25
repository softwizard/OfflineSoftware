# Post-Audit Fix List
### Findings from a full audit of `SoftV_Off-Online` against `build-plan_1.md`, with remediation

**Audit verdict:** the implementation matches the plan. All four invariants hold, A1–A8 are implemented, and the chaos suite covers all 20 scenarios with real multi-database integration. Nothing below is a rewrite — these are six specific defects and gaps found by reading the load-bearing code.

Work them **in order**. F1 and F2 are the only ones that affect correctness or release capability.

---

## GUARDRAILS — do not regress these while fixing

The audit confirmed these are currently correct. Any fix that breaks one is worse than the defect it was fixing.

1. **`recordSyncChange()` writes inside the ambient transaction** (`app/Sync/Syncable.php`). Do not move it out of the model event or wrap it in its own transaction.
2. **`Sync::withoutRecording()` restores via `finally`**, and every remote apply in `RemoteApplier` goes through it. Do not add an apply path that bypasses it.
3. **`SequenceAllocator::next()` is the last statement before the `sync_log` insert** (`app/Sync/LogWriter.php`). Do not hoist it, cache it, or replace `lockForUpdate()` with anything that releases before commit.
4. **`PullRunner` commits the cursor inside the same transaction as the batch.** Do not move the `sync_state` update outside that closure.
5. **The cloud-side `deleting` hook bumps `version` and calls `saveQuietly()`** because Laravel's `runSoftDelete()` never fires `updating`. Removing this makes a concurrent update apply silently over a delete with no conflict recorded. It is load-bearing, not defensive.
6. **`RemoteApplier`'s version gate** (`$model->getVersion() >= $version` → skip) stops a conflict-winning device being regressed by a superseded entry in the same pull page. Keep it.

---

## F1 — Concurrent retry of one change returns a 500 instead of replaying its receipt

**Severity:** medium. No data loss, but a client that retries aggressively over a flaky link gets a hard error where the protocol promises a clean replay.

**File:** `app/Http/Controllers/Sync/PushController.php` → `processChange()`

**What's wrong.** The receipt lookup (A4) happens *before* the transaction; the receipt insert happens *inside* it. Two genuinely concurrent requests carrying the same `change_id` both miss the lookup, both enter their transactions, and both apply. The second then violates `sync_receipts.change_id` (primary key) and its transaction rolls back — so nothing is applied twice, which is why this is not data loss — but the exception escapes as a 500 rather than returning the winner's stored outcome.

**Fix.**

```php
use Illuminate\Database\UniqueConstraintViolationException;

protected function processChange(array $change, Device $device): array
{
    // A4, checked first: a replayed change returns its original outcome
    // verbatim, never reprocessed.
    if ($receipt = $this->existingReceipt($change['id'])) {
        return $receipt;
    }

    try {
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
    } catch (UniqueConstraintViolationException $e) {
        // A concurrent retry of the same change_id committed its receipt
        // while this request was still inside its transaction. That
        // transaction has now rolled back in full — nothing was applied
        // twice — so the correct answer is the winner's stored outcome,
        // exactly what a sequential replay would have returned (A4).
        //
        // Rethrow if no receipt is there: the violation came from some
        // other unique index (customers.code, say) and is a real error.
        return $this->existingReceipt($change['id']) ?? throw $e;
    }
}

/**
 * @return array<string, mixed>|null
 */
protected function existingReceipt(string $changeId): ?array
{
    $row = DB::table('sync_receipts')->where('change_id', $changeId)->first();

    return $row ? json_decode($row->response_payload, true) : null;
}
```

**Verify.** Add `test_a_concurrent_duplicate_push_replays_the_receipt_instead_of_erroring` to `tests/Feature/Sync/PushControllerTest.php`.

Making this deterministic needs the competing receipt insert to be **outside** the request's transaction — a same-connection insert rolls back with it and the test will assert the wrong thing. Use a second connection pointing at the same *file-based* SQLite database (not `:memory:`, which is per-connection). If that proves impractical in the existing harness, fall back to unit-testing both branches of the catch directly: receipt present → returns it; receipt absent → rethrows. Do not ship a test that only exercises the sequential path — that path already passes today.

---

## F2 — No release pipeline, so S7-04 and S7-05 are unproven

**Severity:** medium. The code is complete but undeliverable, and auto-update is the only route to offline field staff who never touch the corporate network.

**Files:** `.github/workflows/` (only `tests.yml` exists), `config/nativephp.php` (already correct)

**What's wrong.** `config/nativephp.php` already sets `updater.default = github` and already lists the `NATIVEPHP_AZURE_*` signing variables. Nothing builds, signs, or publishes — the sole workflow runs tests. A signed installer has therefore never been produced, and the updater has never been exercised end to end.

**Fix.** Add `.github/workflows/release.yml`, triggered on `push: tags: ['v*']`:

1. Check out; set up PHP 8.3 with `extensions: zip`; set up Node 22.
2. `composer install --no-interaction --prefer-dist`, `npm ci`, `npm run build`.
3. Build the Windows bundle.
4. Sign via Microsoft Artifact Signing (formerly Trusted Signing), reading `NATIVEPHP_AZURE_PUBLISHER_NAME`, `NATIVEPHP_AZURE_ENDPOINT`, `NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME`, `NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME` from repository secrets. Never commit these.
5. Publish the artifacts to the GitHub Release for the tag.

**Confirm the build command rather than guessing it.** Run `php artisan list native` against the installed `nativephp/desktop` version and use the exact command and flags it reports. Do not copy a command from a blog post or from this document — the v1 and v2 command surfaces differ.

**Verify.** Cut a `v0.0.1-rc1` tag. The run must produce a signed installer attached to the release. Then install that build on a Windows machine, publish `v0.0.1-rc2`, and confirm the installed app updates itself. Until an installed build has pulled an update, treat S7-05 as incomplete.

---

## F3 — `APP_MODE` defaults to `local`, so a misconfigured server boots as a laptop

**Severity:** low probability, high blast radius. A server that boots in local mode keeps an outbox instead of writing the canonical log, and every device silently stops receiving changes.

**Files:** `config/sync.php`, `app/Providers/SyncServiceProvider.php`

**Fix.** Remove the default so the value must be explicit:

```php
// config/sync.php
'mode' => env('APP_MODE'),
```

Then validate at boot, before anything reads it:

```php
// app/Providers/SyncServiceProvider.php — first lines of boot()
$mode = config('sync.mode');

if (! in_array($mode, ['local', 'cloud'], true)) {
    throw new RuntimeException(
        'APP_MODE must be exactly "local" or "cloud", got '.var_export($mode, true).'. '
        .'There is deliberately no default: a server booting in local mode would keep an '
        .'outbox instead of writing the canonical sync_log, and every device would silently '
        .'stop receiving changes.'
    );
}
```

**Before committing, set `APP_MODE` explicitly in all four places** or every artisan command dies at boot:

- `.env` (local dev)
- `.env.example`
- `phpunit.xml` — already sets `APP_MODE=local`, leave it
- the server's deployment environment, and any Docker/CI env used for cloud-mode tests

**Verify.** Add to `tests/Feature/MigrationsByModeTest.php` (or a new `SyncModeConfigTest`): unsetting the mode throws, and the message names `APP_MODE`. Then run the full suite — a missed `.env` entry surfaces as mass failures, not a subtle one.

---

## F4 — Push uses per-change transactions where the plan specified per-batch

**Severity:** none. This is a deviation to ratify, not a defect.

**File:** `app/Http/Controllers/Sync/PushController.php`

The plan said "apply a batch in one transaction." The implementation gives each change its own, which is what makes the partial-success response (`applied` / `conflict` / `rejected` per change) meaningful — a single poisoned change in a batch of 200 cannot roll back 199 good ones. That is the better call for a single-entity system.

**It stops being the better call when multi-table FK ordering arrives.** A child row pushed in the same batch as its parent needs both to commit together, or the child is rejected for a missing foreign key and the batch half-lands.

**Fix.** No code change. Add a docblock on `store()` recording the decision, the reason, and the condition that reverses it, and note the same in `docs/schema-bump-procedure.md` beside the multi-entity guidance. A silent divergence from the plan becomes a bug the day someone adds invoices.

---

## F5 — Dead fallback in the origin-device resolution

**Severity:** cosmetic, but it misleads a reader about where trust comes from.

**File:** `app/Http/Controllers/Sync/PushController.php` → `applyChange()`

```php
$originDevice = $change['origin_device'] ?? $device->device_code;
```

`$change` comes from `$request->validate()`, which returns only validated keys, and `origin_device` is not among the rules. The left branch is unreachable.

**Fix.** Delete the fallback and always use the authenticated device:

```php
$originDevice = $device->device_code;
```

This is also the more defensible behaviour: `origin_device` is the tiebreak in LWW conflict resolution, so it should come from the Sanctum token rather than from a field the client controls. Keep it that way even if a future change adds the field to validation.

**Verify.** The existing suite covers this path; confirm `ChaosScenariosTest` S04 and S18 still pass, since both depend on the tiebreak.

---

## F6 — Uncommitted working tree

**Severity:** housekeeping, but one of the two files is substantive.

```
 M bootstrap/providers.php
 M docs/windows-packaging-checklist.md
```

`bootstrap/providers.php` carries a real second fix — `nativephp/desktop` 2.2.1 declares `FreshCommand`/`MigrateCommand` names twice (`#[AsCommand]` plus a `$name` property), so Symfony Console throws on any full command enumeration, breaking `php artisan list` and `php artisan tinker`. That reasoning exists only in the working tree right now.

**Fix.** Review both diffs and commit them with a message that names the upstream package version, so the workaround can be removed when `nativephp/desktop` fixes it upstream.

---

## Closing checklist

- [ ] F1 receipt race fixed, with a test that exercises the catch branch rather than the sequential path
- [ ] F2 release workflow added; a signed installer built and an update proven on a real Windows machine
- [ ] F3 `APP_MODE` made explicit, set in `.env`, `.env.example`, and the server environment
- [ ] F4 decision recorded in code and in `docs/`
- [ ] F5 dead fallback removed
- [ ] F6 working tree committed
- [ ] `composer test` green — report the actual pass/skip/fail counts, not "it passes"
- [ ] `composer types:check` green

**On the skipped tests:** the last recorded run shows 17 skips, all in `tests/Feature/Auth` and `tests/Feature/Settings`, all gated by `skipUnlessFortifyHas()` because local mode disables password reset, email verification, and 2FA (S0-06). That is correct and expected. Do not "fix" them by re-enabling the features or deleting the guard.
