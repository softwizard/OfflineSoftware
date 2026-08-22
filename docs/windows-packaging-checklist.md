# Sprint 7 — Windows packaging: what's done vs. what a human has to do

build-plan_1.md's Sprint 7 has seven tasks. Four are code, done and tested
in this repo. Three need a billing decision, a GitHub release push, and a
real corporate laptop — none of which an AI coding agent should do
unattended. This document is the handoff for those three.

## Done (S7-01 – S7-03)

| Task | What's actually in the repo |
|---|---|
| S7-01 | `nativephp/desktop` ^2.2 installed, `config/nativephp.php` published. App identity (`app_id`, `author`, `website`) deliberately left as the package's placeholder defaults — see "App identity" below, that's a business decision, not a technical one. |
| S7-02 | `App\Sync\LocalDatabasePath::assertNotOneDriveRedirected()`, called from `SyncServiceProvider::boot()` in local mode. Compares `%LOCALAPPDATA%` against the `OneDrive`/`OneDriveCommercial`/`OneDriveConsumer` env vars Windows sets when OneDrive is installed; throws `App\Sync\OneDriveRedirectionException` if redirected. Tested in `tests/Feature/LocalDatabasePathTest.php` with both simulated redirection and this real machine's genuine (healthy, non-redirected) OneDrive configuration. |
| S7-03 | `App\Providers\NativeAppServiceProvider::boot()` starts `schedule:work` as a persistent child process (`Native\Desktop\Facades\ChildProcess::artisan(['schedule:work'], 'scheduler', persistent: true)`) — the same pattern NativePHP's own `QueueWorker::up()` uses for queue workers. This is what keeps `routes/console.php`'s `Schedule::command('sync:run')->everyFiveMinutes()` firing with no Windows Task Scheduler entry and no OS cron. Tested in `tests/Feature/NativeAppServiceProviderTest.php` via NativePHP's first-party fakes. |

### A real compatibility issue found and fixed along the way

`nativephp/desktop` 2.2.1's `NativeServiceProvider`, when active during
`php artisan test`, corrupts this project's `CloudTestCase` mechanism
(`tests/Feature/Sync/CloudTestCase.php`): the very first local-mode-to
-cloud-mode `RefreshDatabase` transition in a fresh test process leaves
local-only tables (`sync_changes`, `code_sequences`, ...) behind instead
of dropping them on `migrate:fresh`. Found by bisecting a genuine test
failure down to a minimal repro (`BackoffTest` immediately followed by
`CloudBootSmokeTest`), not by inspection — confirmed by excluding the
provider from package discovery and watching the failure disappear.

Fixed by opting `nativephp/desktop` out of Laravel's auto-discovery
(`composer.json`'s `extra.laravel.dont-discover`) and registering
`Native\Desktop\NativeServiceProvider` manually in
`bootstrap/providers.php`, conditioned on `env('APP_ENV') !== 'testing'`.
Real desktop packaging and NativePHP's own artisan commands
(`native:install`, `native:run`, `native:dev`) are unaffected — none of
those run under `APP_ENV=testing`. See the comment in
`bootstrap/providers.php` for the full reasoning.

This is very likely why `nativephp/desktop`'s repository has an
unreleased `dev-l13-compatibility` branch (seen in `composer show -a
nativephp/desktop` at the time of writing) — 2.2.1 is the latest tagged
release and predates full Laravel 13 support. Worth re-checking whether
a newer tagged release resolves this cleanly before the real signed
build, rather than carrying this workaround indefinitely.

### App identity

`config/nativephp.php`'s `app_id`, `author`, and `website` are still the
package's placeholders (`com.nativephp.app`, etc.) — deliberately, per
your own call when asked during this sprint. Before the first real
signed build, set real values via `.env` (`NATIVEPHP_APP_ID`,
`NATIVEPHP_APP_AUTHOR`, `NATIVEPHP_APP_WEBSITE` — see `.env.example`'s
S7-01/S7-05 section). The app_id becomes the Windows installer's
registry key and taskbar identity; changing it after real users have
installed the app is a breaking change for updates, so get it right
once rather than iterating on it live.

---

## Needs you (S7-04 – S7-06)

### S7-04 — Microsoft Artifact Signing (~$10/mo)

**Blocked on:** an Azure/Microsoft billing decision. Not something to
set up speculatively.

1. Sign up for [Microsoft Trusted Signing](https://azure.microsoft.com/en-us/products/trusted-signing)
   (the current name for what build-plan_1.md calls "Artifact Signing")
   under whatever Azure subscription the team already has, or a new one.
   No hardware token needed — that's the whole point of this over a
   traditional EV code-signing cert.
2. Create a Trusted Signing account + a certificate profile (public
   trust, "Public Trust" profile type for a real installer distributed
   outside the org, not "Private Trust").
3. `config/nativephp.php`'s `azure_trusted_signing` block already has the
   env keys wired up (`AZURE_TENANT_ID`, `AZURE_CLIENT_ID`,
   `AZURE_CLIENT_SECRET`, `NATIVEPHP_AZURE_PUBLISHER_NAME`,
   `NATIVEPHP_AZURE_ENDPOINT`, `NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME`,
   `NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME`) — fill them into `.env`
   once the Trusted Signing account exists; nothing else in the app
   needs to change.
4. Add those same values as **CI secrets** (GitHub Actions repository
   secrets, or whatever CI this project ends up on) — signing has to
   happen in CI on every release build, not by hand on someone's laptop,
   or "signed on every release build" (the plan's actual "done when")
   silently stops being true the first time someone forgets.
5. First real test: build the installer once with signing configured and
   confirm Windows SmartScreen doesn't flag it, and `signtool verify
   /pa` (or the NSIS-equivalent check) reports a valid signature.

### S7-05 — GitHub Releases + updater

**Blocked on:** the project having a real GitHub remote with release
permissions, which isn't something to assume exists or push to
speculatively.

`config/nativephp.php`'s updater is already pointed at `'github'`
(changed from the package's own default of DigitalOcean Spaces — see
this sprint's commit). What's left:

1. Confirm the GitHub repo this project will actually ship releases
   from (push access, and decide public vs. private — `GITHUB_PRIVATE`
   in `.env.example` covers the private case, which needs
   `GITHUB_AUTOUPDATE_TOKEN` set to a read-only PAT so installed clients
   can check for updates without a full-access token sitting on every
   laptop).
2. Set `GITHUB_OWNER` / `GITHUB_REPO` / `GITHUB_TOKEN` in `.env` (and as
   CI secrets for the release build itself).
3. Decide `GITHUB_RELEASE_TYPE` — `.env.example` doesn't override the
   package default (`draft`); `draft` means a release doesn't go live
   for the updater until someone manually publishes it on GitHub, which
   is probably the right default for a first real rollout (review before
   it auto-updates a fleet), but confirm that's actually the intent.
4. **The actual test** (this is the plan's literal "done when" for
   S7-05): cut a real tagged release, confirm the signed installer
   (S7-04) attaches to it, install that build on a real client machine,
   bump the version, cut a second release, and confirm the running app
   picks up the update on its own. This can't be simulated — it needs a
   real GitHub release and a real second machine (or the same machine,
   twice).

### S7-06 — IT sign-off

**Blocked on:** an actual conversation with whoever owns IT for the
target fleet. build-plan_1.md's §6 ("WINDOWS ROLLOUT CHECKLIST") already
covers the general items — OneDrive/KFM, antivirus exclusions, long
paths, BitLocker/SKU audit, backup ownership, deployment via
Intune/GPO, auto-update staying on despite managed deployment. Re-read
that section directly rather than duplicating it here; nothing about it
changed this sprint.

What Sprint 7 specifically adds to that conversation, since it wasn't
concrete until the packaging work above existed:

- The **actual installer file** to hand to IT for the AppLocker allow
  rule and the AV exclusion — doesn't exist until S7-04/S7-05 produce a
  real signed build. Don't try to get an exclusion approved for a
  hypothetical file path.
- Whether the target fleet's antivirus needs the exclusion to cover
  `schedule:work`'s child process specifically (S7-03) — a
  long-running, five-minute-interval background PHP process is exactly
  the kind of thing heuristic AV flags, more so than the app's own
  startup.
- Confirming `%LOCALAPPDATA%\<app>\` (S7-02's guarded path) is inside
  whatever the AV exclusion actually covers — if IT scopes the exclusion
  to the install directory only, this app's *data* directory is a
  separate path that needs its own line item.

---

## Needs real hardware (S7-07)

**Blocked on:** a real corporate laptop running the actual fleet SOE
(Standard Operating Environment) image — not a clean VM, and not this
development machine. The whole point of this task is catching what a
clean environment hides: corporate group policy, a different AV
product/config than dev, a non-admin user account, whatever software
IT bakes into the base image that might collide with this app.

Checklist for whoever has access to that hardware:

- [ ] Install via the actual planned deployment mechanism (Intune/GPO
      push per §6's checklist), not a manual double-click, if that's how
      it'll really reach users.
- [ ] Full customer CRUD (Sprint 5) works as a non-admin user.
- [ ] A real sync cycle completes against the real cloud server (not
      just localhost) — push, pull, conflict handling.
- [ ] `schedule:work`'s background process (S7-03) is still alive after
      the laptop sleeps/wakes, locks/unlocks, and after a normal
      Windows Update reboot — none of that is testable on a dev machine
      kept awake and logged in the whole time.
- [ ] The OneDrive guard (S7-02) behaves correctly on an account where
      IT's actual KFM policy is applied — this dev machine's OneDrive
      setup is healthy (verified this sprint), which proves the
      *detection logic* works, not that the *real fleet's* redirection
      policy is actually off.
- [ ] The auto-updater (S7-05) actually reaches a laptop on a
      **non-corporate network** — §6's checklist explicitly calls out
      that field staff are never on the corporate network, so Intune
      can't be what patches them; this is the one thing standing in for
      that.
