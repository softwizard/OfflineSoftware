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

### Two real compatibility issues found and fixed along the way

`nativephp/desktop` 2.2.1's `NativeServiceProvider`, when active during
`php artisan test`, corrupts this project's `CloudTestCase` mechanism
(`tests/Feature/Sync/CloudTestCase.php`): the very first local-mode-to
-cloud-mode `RefreshDatabase` transition in a fresh test process leaves
local-only tables (`sync_changes`, `code_sequences`, ...) behind instead
of dropping them on `migrate:fresh`. Found by bisecting a genuine test
failure down to a minimal repro (`BackoffTest` immediately followed by
`CloudBootSmokeTest`), not by inspection — confirmed by excluding the
provider from package discovery and watching the failure disappear.

Separately: `Native\Desktop\Commands\FreshCommand` and `MigrateCommand`
each declare their command name twice (a `#[AsCommand]` attribute *and*
a `protected $name` property override) — enough to make Symfony
Console's full command-list resolution throw "registered under multiple
names" the instant anything enumerates every registered command.
`php artisan list` hits this directly; `php artisan tinker` hits it
internally (it builds its own PsySH command list from every artisan
command) — so with the provider active outside tests too, two everyday
dev tools were simply broken. Found by trying to actually use the app
after Sprint 8, not by inspection.

Both fixed the same way: `nativephp/desktop` is opted out of Laravel's
auto-discovery (`composer.json`'s `extra.laravel.dont-discover`) and
`Native\Desktop\NativeServiceProvider` is registered manually in
`bootstrap/providers.php`, only when something actually needs it — a
`native:*` artisan command is being invoked (covers `native:install`,
`native:run`, `native:dev`, and `php artisan help native:install`), the
packaged app's own runtime is running it (`NATIVEPHP_RUNNING`, set by
that runtime itself), or never under `APP_ENV=testing`. Everything else
— `tinker`, `list`, `migrate`, `serve`, `route:list`, the test suite —
now boots without the provider at all, so neither bug has anything to
trigger on. See the comment in `bootstrap/providers.php` for the full
reasoning.

Tried switching to the package's unreleased `dev-l13-compatibility`
branch first, in case it already fixed this properly upstream — it
doesn't resolve for this project's actual dependency tree at all
(`symfony/finder`/`symfony/filesystem` version conflicts with what
Laravel 13 itself requires, confirmed via `composer require ... --dry-run
--with-all-dependencies`, which composer safely rejected without
touching anything). 2.2.1 remains the latest genuinely installable
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

### The release workflow now exists — `.github/workflows/release.yml`

Triggered on pushing a `v*` tag; builds on `windows-latest`, installs
PHP/Node/composer/npm dependencies, and runs
`php artisan native:build win x64 --publish`. That exact command and its
flags were **confirmed by reading the installed package's own source**
(`vendor/nativephp/desktop/src/Drivers/Electron/Commands/
BuildCommand.php` and `PublishCommand.php`), not guessed — `php artisan
list native` itself can't be used to check this the normal way, because
it hits the exact same "registered under multiple names" bug the
`bootstrap/providers.php` fix above works around (enumerating commands,
which `list` always does even namespace-filtered, is exactly what
triggers it).

The workflow reads all signing/publishing credentials from GitHub
Actions **repository secrets** — nothing is hardcoded, nothing is
committed. What's actually still needed is the credentials themselves
and a real run:

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
3. Add these as **GitHub Actions repository secrets** — the workflow
   already reads them, nothing else needs to change:
   `AZURE_TENANT_ID`, `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET`,
   `NATIVEPHP_AZURE_PUBLISHER_NAME`, `NATIVEPHP_AZURE_ENDPOINT`,
   `NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME`,
   `NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME`.
4. First real test: push a tag, let the workflow build the installer
   with signing configured, and confirm Windows SmartScreen doesn't flag
   it, and `signtool verify /pa` (or the NSIS-equivalent check) reports a
   valid signature.

### S7-05 — GitHub Releases + updater

**Blocked on:** the project having a real GitHub remote with release
permissions, which isn't something to assume exists or push to
speculatively — this repo currently has **no remote configured at all**
(found during the post-Sprint-8 audit), so neither `tests.yml` nor this
new `release.yml` has ever actually run.

`config/nativephp.php`'s updater is already pointed at `'github'`
(changed from the package's own default of DigitalOcean Spaces — see
Sprint 7's commit). What's left:

1. Confirm the GitHub repo this project will actually ship releases
   from (push access, and decide public vs. private — `GITHUB_PRIVATE`
   in `.env.example` covers the private case, which needs
   `GITHUB_AUTOUPDATE_TOKEN` set to a read-only PAT so installed clients
   can check for updates without a full-access token sitting on every
   laptop).
2. Add a **repository secret** named `GH_RELEASE_TOKEN` (a PAT with
   `contents: write` on this repo — `release.yml` reads it as
   `GITHUB_TOKEN` inside the build) and **repository variables**
   `NATIVEPHP_APP_ID` / `NATIVEPHP_APP_AUTHOR` / `NATIVEPHP_APP_WEBSITE`
   (the real branding decided before the first real build — see "App
   identity" above). `GITHUB_OWNER`/`GITHUB_REPO` are derived
   automatically from the repository itself in the workflow; no need to
   set those by hand.
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
