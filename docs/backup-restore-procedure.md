# Server backup & restore procedure (S6-07)

build-plan_1.md scopes the server DB as "PostgreSQL 16+, MySQL 8
acceptable if the team knows it better" (§1) — this project runs MySQL 8
via XAMPP, so this is the MySQL-native version of that task: `mysqldump`
in place of `pg_dump`, binary logs in place of WAL archiving. Same goal:
a nightly base backup plus a rehearsed restore procedure, with RPO/RTO
written down rather than assumed.

## What backs this up

`scripts/backup-mysql.ps1` — `mysqldump --single-transaction` against the
cloud database (default `softv_off_online_cloud`), zipped and written to
`scripts/backups/` (git-ignored — dump files contain real customer data).
`--single-transaction` takes a consistent InnoDB snapshot without table
locks, so a nightly run does not block push/pull traffic.

```powershell
pwsh scripts/backup-mysql.ps1
pwsh scripts/backup-mysql.ps1 -Database softv_off_online_cloud -RetentionDays 14
```

### Scheduling it

Not registered automatically by this repo — hand this to whoever owns
the server, per §6's rollout checklist ("RPO/RTO agreed with whoever
owns the server, not assumed"):

```powershell
schtasks /Create /TN "SoftV Cloud Backup" /TR "pwsh -File C:\path\to\scripts\backup-mysql.ps1" /SC DAILY /ST 02:00
```

## Restoring

`scripts/restore-mysql.ps1` loads a dump into a target database.

**The one thing that will bite you:** `mysqldump --databases <name>`
embeds `CREATE DATABASE` and `USE <name>;` statements naming the
*original* database. If you restore into a differently-named target (as
you always should when rehearsing — see below), those `USE` statements
win over whatever database name you pass on the command line, and you'll
silently write into the original name instead. Rewrite the name in the
dump text before replay whenever the target name differs from the
source:

```bash
sed 's/`softv_off_online_cloud`/`softv_off_online_cloud_restore_test`/g' \
    dump.sql > dump_retargeted.sql
mysql -u root < dump_retargeted.sql
```

`scripts/restore-mysql.ps1` documents this same gotcha inline; it does
not do the substitution for you, because doing it silently is exactly
how you'd end up overwriting production by accident during a "safe"
rehearsal.

**Restoring into production** (the target name matches the dump's
original name) has no such trap — just:

```powershell
pwsh scripts/restore-mysql.ps1 -DumpFile scripts\backups\softv_off_online_cloud_2026-08-24_020000.sql.zip -TargetDatabase softv_off_online_cloud
```

## Rehearsal — actually run, 2026-08-22

Performed against the real server database (not a description of what
*would* happen), per §6's "restore procedure rehearsed on a scratch
server":

1. Seeded `softv_off_online_cloud` (25 demo customers via
   `DatabaseSeeder`), provisioned a real device (`D50`), and pushed one
   customer through a live `php artisan serve` instance — 26 customers,
   1 `sync_log` row, sequence at 1.
2. Ran `backup-mysql.ps1` — **657 ms**.
3. Retargeted the dump to `softv_off_online_cloud_restore_test` (the sed
   step above) and restored it — **287 ms**.
4. Verified row-for-row: `SELECT COUNT(*)` on `customers`, `sync_log`,
   `sync_sequence`, and `devices` in the restored copy matched the
   source exactly, and the pushed customer's data was intact byte for
   byte.
5. **S2-07/S2-08 smoke checks against the restored copy**, not just a
   row count — started a second real `php artisan serve` instance
   pointed at the restored database:
   - Pushed a new change through `D50`'s still-valid token, then
     retried the identical batch — both calls returned the byte-identical
     response (S2-07's receipt idempotency: `sync_receipts` survived the
     restore intact).
   - Provisioned a fresh device (`D51`) against the *restored* copy and
     pulled from cursor 0 — got both customer records, in the correct
     seq order, cursor advanced to 2, no duplicates, nothing missing
     (S2-08).
6. Cleaned up: dropped `softv_off_online_cloud_restore_test`, stopped
   both server instances, `migrate:fresh --seed` back on
   `softv_off_online_cloud` to leave it in the same clean state every
   other sprint's manual verification leaves it in.

## RPO / RTO

Measured at this dataset's current size (~26 rows); both scale with data
volume, so re-measure before the pilot once real fleet data exists.

| | Value | Basis |
|---|---|---|
| Backup duration | 657 ms | Step 2 above, real `mysqldump --single-transaction` |
| Restore duration | 287 ms | Step 3 above, real `mysql` replay |
| **RPO** (data loss on total server loss) | **up to 24h** on the baseline nightly-only schedule | Worst case: server dies 23h59m after last backup |
| **RTO** (time to a working, verified-smoke-tested server) | **restore time + smoke checks**, minutes not hours at this scale | Steps 3–5 above, done by hand in well under 5 minutes total |

24h RPO is the *baseline* this procedure delivers today — nightly
`mysqldump` only. It is not tight enough for invariant 3 (the sequence
counter) to be comfortable losing a full day of allocations across a
live pilot fleet. **Before the pilot**, tighten it by enabling MySQL
binary logging (`log_bin` in `my.ini`) and archiving binlogs on the same
cadence as `sync:run`'s own 5-minute scheduler
(`routes/console.php`), which drops RPO to roughly that interval via
point-in-time recovery (`mysqlbinlog` replay on top of the last full
dump). That change requires editing `my.ini` and restarting the MySQL
service — a live, hard-to-reverse change to a shared server — so it's
deliberately not made by this repo or this rehearsal; it's IT's call,
timed with the rest of §6's rollout checklist, not something to flip
quietly during development.
