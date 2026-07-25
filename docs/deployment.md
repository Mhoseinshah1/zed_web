# Atomic release-based deployment

ZedProxy deploys by building a brand-new **release** in an isolated directory and
activating it with an atomic `current` symlink switch. The old in-place
`git reset --hard` update is gone: a broken build can never become partially
live, and an activation failure automatically rolls the **code** back to the
previous release.

## Filesystem layout

```
/var/www/zedproxy/
├── current -> releases/<release-id>     # atomic symlink; the Nginx root
├── releases/
│   ├── 20260723140501-abcdef123456/
│   └── ...
└── shared/
    ├── .env                             # single source of the encryption key
    ├── storage/                         # uploads, logs (storage/app/public)
    └── persistent/
```

Each release links its `.env`, `storage`, and `public/storage` to `shared/`, so
the `APP_KEY` and uploaded files are identical across releases and never
rewritten. **Fresh installations create this layout from the very first
install** — there is no separate "install into `/var/www/zedproxy` directly then
migrate later" step. The Nginx root, the Supervisor worker command, and the
scheduler cron all target `current/` immediately.

> **Nginx root** resolves through `current`: `root /var/www/zedproxy/current/public;`.
> The Nginx/Supervisor/scheduler cutover is performed **automatically** by the
> installer (fresh) and by the first `zedproxy-update` (legacy migration). No
> manual cutover step is required.

## Persistent deployment configuration (`/etc/zedproxy/deploy.env`)

The installer writes a root-only (`600`), **non-secret** config file that every
update/rollback/deploy-status entrypoint loads:

```
ZPD_BASE=/var/www/zedproxy
ZPD_REPO_URL=https://github.com/Mhoseinshah1/zed_web.git
ZPD_REF=main
ZPD_HEALTH_URL=http://127.0.0.1
```

It **must never** contain passwords, tokens, `APP_KEY`, DB credentials, or an
authenticated repository URL. Existing custom values are preserved on installer
re-runs. Explicit environment variables override the file.

### Repository-source resolution (precedence)

`zedproxy-update` works **from any directory** (`/root`, `/tmp`, `/`, a home dir,
the legacy app dir, the active release) without an operator supplying
`ZPD_REPO_URL`. The source is resolved by precedence:

1. explicit `ZPD_REPO_URL`
2. `/etc/zedproxy/deploy.env`
3. git `origin` of the active release (`current/`)
4. git `origin` of a detected legacy install
5. built-in public default `https://github.com/Mhoseinshah1/zed_web.git`

The fallback is **never** `.` (the caller's working directory): a relative path
or `.` is rejected, and an absolute local path is honoured only under the
explicit test/dev flag `ZPD_ALLOW_LOCAL_REPO=1`. If no safe source resolves, the
deploy aborts with «آدرس مخزن پروژه قابل تشخیص نیست. فایل /etc/zedproxy/deploy.env را بررسی کنید.»

### Exact ref resolution

`ZPD_REF` may be a branch, lightweight tag, annotated tag, or full commit SHA.
The exact full SHA is resolved from the remote (`git ls-remote`) **before** the
release is named, so a release id always embeds the deployed commit and can
never end in `-nogit`. After clone the deployer verifies
`git -C <release> rev-parse HEAD` equals the resolved SHA and aborts on mismatch,
so the manifest always describes the code that is actually deployed.

### Git error visibility

Critical git operations no longer send stderr to `/dev/null`. Output is captured
and printed through the secret-redaction layer, so operators see the real
non-sensitive cause (repo not found, DNS/TLS failure, branch/tag not found,
permission denied, disk failure, destination conflict) while credentials in an
authenticated URL are shown as `[REDACTED]`.

## Commands

| Command | Purpose |
| --- | --- |
| `zedproxy-update` | Self-bootstrapping atomic update (works from any directory). |
| `zedproxy-rollback [release-id\|legacy] [--yes]` | Switch `current` back to a previous healthy release (or restore the legacy app on the first cutover). |
| `zedproxy-deploy-status [--json]` | Show base, active/previous release, `current →` target, repo, ref, deployed SHA, result, migrations, health. |
| `zedproxy-sanitize-install-log [--scan\|--redact\|--truncate]` | Clean an old install log. |

The shortcuts are **stable bootstrap wrappers**: they load `deploy.env`, resolve
the target script from the active release first (falling back to a detected
install), never depend on `$PWD`, and avoid unnecessary nested `sudo`.
`zedproxy-update` runs the self-bootstrapping `update.sh`, which fetches fresh
deploy logic into a temporary directory rather than blindly executing an on-disk
copy that might be an older, broken script.

## Inspecting the active release & deployed SHA

```bash
zedproxy-deploy-status            # human-readable
zedproxy-deploy-status --json     # machine-readable
readlink /var/www/zedproxy/current
git -C /var/www/zedproxy/current rev-parse HEAD
```

## Release identifier & manifest

`YYYYMMDDHHMMSS-<short-git-sha>`. Every release writes a secret-free
`RELEASE_MANIFEST.json` (release id, git SHA, previous release, start/finish
time, result, migration status, health). A machine-readable state file lives at
`shared/deploy/state.json`. Logs are stored outside release directories under
`/var/log/zedproxy/` with restricted permissions and masked secrets.

## Deployment flow

1. **Lock** — a single host-level `flock` (`/var/run/zedproxy-deploy.lock`, not
   inside a release). A second deployment fails immediately with
   «یک عملیات به‌روزرسانی دیگر در حال اجرا است.» The lock releases on success,
   failure, or interruption.
2. **Preflight** (before touching anything live): `.env`, non-empty `APP_KEY`,
   php/composer/npm present, writable shared dirs, enough disk. Abort here on
   failure — before maintenance mode.
3. **Backups** — `.env` (600) and a PostgreSQL dump (600). **If the DB backup
   fails, the deployment stops.** Secrets are never printed.
4. **Build the new release** in isolation (`composer install --no-dev`, `npm ci`,
   `npm run build`, `optimize:clear` + `config/route/view:cache`) then a smoke
   test. A composer/npm/build failure marks the release `.failed` and leaves the
   current release untouched.
5. **Atomic activation**: maintenance mode → pause workers → run migrations →
   **atomic symlink switch** → reload PHP-FPM → `nginx -t` → reload Nginx →
   restart workers → **Phase A internal readiness (still in maintenance)** →
   **`php artisan up`** (verified: maintenance flag actually cleared) →
   **Phase B public HTTP readiness** → success only if every phase passed.
6. **Two-phase health** — see *Two-phase readiness* below. Phase A runs the
   infra/CLI/service checks that do **not** need a public application response
   (safe while the app returns a maintenance `503`); only after the app is
   brought online does Phase B hit the public HTTP endpoints. A failed required
   check fails the deployment; the success flag is never set after a failed check.

## Migration policy

Migrations are classified (`zpd_migration_preflight`): backward-compatible
(add-only) vs **destructive** (dropColumn/dropTable/delete/truncate/renameColumn).
Destructive migrations are reported and can be gated behind manual approval. The
deployer **never** auto-runs destructive rollback migrations.

If migrations succeeded but activation failed, the **code** is rolled back
automatically and the DB backup is kept. The tool reports
«بازیابی خودکار کد انجام شد، اما مهاجرت‌های دیتابیس نیاز به بررسی دارند.» and the
dump path — it never claims a full rollback when the database was not restored.

## Rollback

`zedproxy-rollback` lists releases, defaults to the immediately previous
**healthy** release, requires explicit confirmation, switches `current` back,
reloads services, and re-runs health checks. It **never** rotates `APP_KEY` and
**never** removes uploaded files (only the symlink moves).

## Retention

Keep the newest N successful releases (`ZPD_KEEP_RELEASES`, default 5). The
active and immediately-previous releases are never deleted. Failed-release
metadata is kept for diagnosis. Database backups are **not** pruned by release
retention. Disk is checked before and after cleanup.

## Result messages (Persian)

- Success: «به‌روزرسانی با موفقیت انجام شد.»
- Rolled back: «به‌روزرسانی ناموفق بود و نسخه قبلی بازیابی شد.»
- Previous active: «نسخه قبلی با موفقیت فعال شد.»
- DB review needed: «بازیابی خودکار کد انجام شد، اما مهاجرت‌های دیتابیس نیاز به بررسی دارند.»

## Automatic legacy migration (existing single-directory install)

The first `zedproxy-update` on a legacy install (`/var/www/zedproxy` is a plain
Laravel checkout with no `current` symlink) performs the migration **fully
automatically** — no reinstall, no database recreation, and **no rotation** of
`APP_KEY`, DB password, admin password, payment secrets, VPN panel credentials,
or Telegram tokens:

1. Detect the legacy app; verify `.env`, `artisan`, `composer.json`, DB.
2. Determine its git `origin`; back up `.env` and PostgreSQL.
3. Move `.env` + `storage` into `shared/` (content unchanged).
4. Build the new release in isolation **without touching the live app**.
5. Snapshot the current Nginx/Supervisor/scheduler config for rollback.
6. Enter maintenance mode only immediately before cutover.
7. Atomically switch `current`, then repoint Nginx (`current/public`),
   Supervisor (`current/artisan`), and the scheduler (`current/artisan`);
   `nginx -t` is run before reload.
8. Verify health; exit maintenance on success.

### First-cutover rollback

On the very first migration there is no previous release id — the **legacy
application itself** is the rollback target. If the first activation fails, the
deployer restores the snapshotted Nginx/Supervisor/scheduler config **and the
previous command wrappers**, drops the `current` symlink so Nginx serves the
legacy root again, reloads services, brings the legacy app out of maintenance
mode, and confirms legacy health. It reports «فعال‌سازی نسخه جدید ناموفق بود و
نصب قبلی (legacy) بازیابی شد.» and never claims success unless the legacy app is
healthy. The failed release is kept as `<id>.failed` for diagnosis.
`zedproxy-rollback legacy` triggers the same path manually.

### Strict, fail-closed cutover

The Nginx / Supervisor / Scheduler cutover **fails closed** — a partial cutover
can never be reported as success:

- **Supervisor**: the worker config must exist (or is created explicitly),
  is rewritten to `current/artisan` with logs in shared storage, is verified to
  contain the exact `current/` path (a lingering legacy `<base>/artisan` command
  is rejected), and `supervisorctl reread`, `update`, and the worker restart
  must all succeed — **none is masked by `|| true`**. Failure →
  «به‌روزرسانی تنظیمات صف پردازش ناموفق بود و نسخه قبلی بازیابی می‌شود.»
- **Scheduler**: the complete cron file is written **atomically** (root:root,
  mode 644) and verified to contain exactly **one** `current/artisan schedule:run`
  entry (duplicates and legacy paths rejected). Failure →
  «انتقال زمان‌بندی Laravel به نسخه جدید ناموفق بود و تنظیمات قبلی بازیابی می‌شود.»

### Command wrappers refreshed on every update

The stable `zedproxy-*` wrappers + `/usr/local/lib/zedproxy/bootstrap.sh` are
installed from **one** shared implementation
(`scripts/deploy/install-command-wrappers.sh`) used by BOTH `install.sh` and
`deploy.sh`. Because a legacy server migrates through `update.sh → deploy.sh`
(never `install.sh`), `deploy.sh` **(re)installs the wrappers on every
activation** — this is what removes an old, broken `zedproxy-update` that still
executed the legacy base deployer with the original `.` repository fallback. On
a first legacy cutover the previous wrappers are snapshotted and restored if the
activation fails.

### Two-phase readiness (maintenance-safe)

Readiness is split so a maintenance-mode `503` can never be mistaken for an
unhealthy release (the bug that previously caused false rollbacks):

- **Phase A — internal readiness (runs while still in maintenance):** the
  `current` symlink exists and resolves to the expected release id; the manifest
  SHA equals the deployed `git HEAD`; `nginx -t`; the Nginx root, Supervisor
  command, and scheduler cron all go through `current/`; the worker group is
  **running**; `php current/artisan schedule:list` succeeds; the command
  wrappers resolve `current/`; PostgreSQL + Redis are reachable; shared storage
  is writable; `artisan`, `public/index.php`, and the Vite `public/build/manifest.json`
  exist; and a **bounded** `php artisan zedproxy:health --json`
  (`ZPD_HEALTH_CLI_TIMEOUT`, default 20s) passes. None of these needs a public
  HTTP response, so they run truthfully while the app is fenced.
- **`php artisan up`:** the app is brought online and the maintenance flag
  (`storage/framework/maintenance.php`) is verified **actually cleared** — a
  stuck maintenance state fails activation. `artisan up` is never masked by
  `|| true`.
- **Phase B — public HTTP readiness (only after `up`):** `/health`,
  `/health/live`, homepage, `/login`. If Phase B fails, the failed release is put
  **back** into maintenance before the caller rolls back, so it stops serving.

The deployment is **never** reported successful while Queue or Scheduler still
runs legacy code, nor while the app is still in maintenance.

### Internal loopback health vhost

Phase B is validated against an internal Nginx vhost bound to **loopback only**
(`127.0.0.1:18080` → `current/public`), so deployment health never depends on
Cloudflare, public DNS, public TLS, or the public vhost. The installer creates
`/etc/nginx/conf.d/zedproxy-local-health.conf`; the deployer **repairs** it on
every run (validated with `nginx -t`, restored on failure) and writes
`ZPD_HEALTH_URL=http://127.0.0.1:18080` into `deploy.env`. Every `listen`
directive is asserted loopback — a config that exposes the port on a public
interface is rejected and repaired. A custom `ZPD_HEALTH_URL` is preserved. No
`curl -k` / TLS-skip is used anywhere.

### Honest migration-state reporting

`artisan migrate --force` output (which is **not** localized) is parsed into one
of `not_run | none_pending | applied | failed`, recorded in the manifest. The DB
backup warning and «مهاجرت‌های دیتابیس نیاز به بررسی دارند» notice appear **only
when a migration actually ran** (`applied`) — a deploy that changed nothing no
longer prints a misleading "check your database backup" message. The backup is
always preserved regardless.

### Bounded, non-interactive tool-version probes

Informational toolchain metadata (git tag, php/composer/node/npm versions) is
collected through `dep_probe_version`: every probe is wrapped in
`timeout ${ZPD_PROBE_TIMEOUT}s` (default 5s), reads stdin from `/dev/null`, and
runs non-interactively (`COMPOSER_NO_INTERACTION=1`, `GIT_TERMINAL_PROMPT=0`, …),
returning `unknown` on any failure/timeout/malformed output. The whole collection
stage is additionally bounded by a global `ZPD_META_TIMEOUT` (default 30s) and
logged (`Collecting release metadata…` / `Release metadata collected.`), so a
hung `composer --version` can **never** freeze the terminal or block activation.

### Interrupt safety

A `SIGINT`/`SIGTERM` during a deploy records the current stage, brings the active
release **out of maintenance** (bounded `artisan up`), restarts the worker group,
releases the deploy lock, and exits **non-zero** — an interrupted deploy is never
reported as success and never leaves the app stuck in maintenance or with stopped
workers.

### Strict updater ref (`update.sh`)

`update.sh` resolves the exact updater commit from the remote (`git ls-remote`:
branch / lightweight tag / annotated tag / full SHA) **before** fetching, fails
clearly when the ref does not exist («نسخه درخواستی به‌روزرسان قابل بازیابی نیست.
عملیات متوقف شد.»), checks out that ref **strictly** (a checkout failure stops
immediately — never `|| true`), and verifies the fetched `HEAD` equals the
resolved SHA before running `deploy.sh`. A custom ref never silently falls back
to the default branch.

## Configuration (env)

Loaded from `/etc/zedproxy/deploy.env` (non-secret) or the environment:
`ZPD_BASE`, `ZPD_REPO_URL`, `ZPD_REF`, `ZPD_HEALTH_URL` (default the loopback
`http://127.0.0.1:18080`), `ZPD_KEEP_RELEASES`, `ZPD_MIN_DISK_MB`. Timeouts:
`ZPD_PROBE_TIMEOUT` (per version probe, 5s), `ZPD_META_TIMEOUT` (metadata stage,
30s), `ZPD_HEALTH_CLI_TIMEOUT` (`zedproxy:health`, 20s). Loopback health vhost:
`ZPD_LOCAL_HEALTH_CONF`, `ZPD_LOCAL_HEALTH_PORT` (18080), `ZPD_FPM_SOCK`.
Additional runtime paths: `ZPD_LOCK_FILE`, `ZPD_LOG_DIR`, `ZPD_BACKUP_DIR`,
`ZPD_STATE_FILE`. Test/dev only: `ZPD_ALLOW_LOCAL_REPO=1` (permit an absolute
local repo path), `ZPD_ALLOW_NOGIT=1` (permit a `-nogit` release id in fixtures).
Injectable command names (`ZPD_COMPOSER`, `ZPD_NPM`, `ZPD_NODE`, `ZPD_PHP`,
`ZPD_PG_DUMP`, `ZPD_SYSTEMCTL`, `ZPD_NGINX`, `ZPD_SUPERVISORCTL`, `ZPD_CURL`,
`ZPD_PSQL`, `ZPD_REDIS_CLI`, `ZPD_GIT`) and config-file paths (`ZPD_NGINX_CONF`,
`ZPD_SUPERVISOR_CONF`, `ZPD_SCHED_CRON`, `ZPD_DEPLOY_ENV`) let the shell tests
drive every step against temporary files.

## Troubleshooting git failures

If `zedproxy-update` reports «دریافت کد پروژه از GitHub ناموفق بود.», the real
cause is printed above it (redacted). Common cases:

- **Repository not found / branch not found** — check `ZPD_REPO_URL`/`ZPD_REF`
  in `/etc/zedproxy/deploy.env`.
- **DNS/TLS failure** — check outbound HTTPS to `github.com`.
- **`آدرس مخزن پروژه قابل تشخیص نیست`** — no source resolved; set `ZPD_REPO_URL`
  or fix `deploy.env`.
- Recover a server whose on-disk updater is broken with the one-liner:
  `curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/update.sh -o /tmp/u.sh && sudo bash /tmp/u.sh`.

## Tests

`bash tests/deploy/run-tests.sh` runs 140+ scenarios using **real temporary git
repositories** (a local bare "remote" with `main`, commits, a lightweight tag,
and an annotated tag) plus mocked non-git system commands — including mocks that
**hang, read stdin, exit non-zero, delay, or emit malformed output**, a
maintenance-aware `php` mock (`artisan down/up` toggles the real
`storage/framework/maintenance.php` flag), and a maintenance-aware `curl` mock
(returns `503` exactly while the served release is in maintenance). It exercises
real `git clone` / `ls-remote` / `checkout` / `rev-parse`; repository/ref
resolution and rejection of the `.` fallback; bounded non-interactive probes
(hang/stdin/non-zero/malformed → `unknown`, global metadata deadline); honest
migration-state reporting (`none_pending`/`applied`/`failed`, no false DB
warning); bounded CLI health (timeout/component failure); the loopback health
vhost (create/repair/loopback-only/port-18080/custom-URL preservation);
**internal readiness passing in maintenance while `/health` returns 503**;
**`artisan up` before the public HTTP check** (stuck-maintenance and HTTP-failure
both fence the release and fail activation, never falsely succeeding on HTTP);
rollback that brings the previous release up **before** verifying it; interrupt
recovery (exit maintenance, non-zero exit, lock released); first legacy cutover
(success + failure→legacy restore); normal update + rollback; and clone/build
failure isolation. The production GitHub repository is never contacted.
`bash tests/installer/run-tests.sh` adds static assertions that the installer
builds the atomic layout and points Nginx/Supervisor/scheduler at `current`. The
CI **Shell Tests** job additionally asserts, by static analysis, that no
unbounded `composer --version` exists and that `artisan up` precedes the public
HTTP readiness check in `dep_activate`.
