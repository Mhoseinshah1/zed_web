# ZedProxy

A production-ready VPN/proxy sales platform built with Laravel, PostgreSQL, Redis, Filament, and Tailwind CSS. Designed to scale to 40,000+ users with full RTL Persian support.

## Tech Stack

| Component     | Technology                              |
|---------------|-----------------------------------------|
| Backend       | Laravel 12, PHP 8.3+                    |
| Database      | PostgreSQL 16+                          |
| Cache/Queue   | Redis                                   |
| Frontend      | Blade + Tailwind CSS (RTL)              |
| Admin Panel   | Filament v3                             |
| Web Server    | Nginx + PHP-FPM                         |
| OS            | Ubuntu 22.04, 24.04, 26.04+            |

## Requirements

- Ubuntu 22.04 (jammy), 24.04 (noble), or 26.04 (resolute) — see [Supported OS](#supported-os)
- **PHP 8.3 or higher**, compatible with Ubuntu 24.04 official packages — extensions: pgsql, redis, mbstring, xml, curl, zip, bcmath, gd, intl, opcache
- PostgreSQL 14+
- Redis 6+
- Node.js 22+, npm
- Composer 2+

## Supported OS

The installer supports Ubuntu releases where official packages or a verified PPA can provide PHP 8.3 or higher.

| Ubuntu Release | Codename  | PHP Source              | Status     |
|----------------|-----------|-------------------------|------------|
| 22.04 LTS      | jammy     | ondrej/php PPA          | Supported  |
| 24.04 LTS      | noble     | Official Ubuntu packages| Supported  |
| 26.04          | resolute  | Official Ubuntu packages| Supported  |

**How PHP is installed:**

1. The installer always tries official Ubuntu packages first (`apt-get install php php-fpm ...`).
2. It detects the installed PHP version automatically (`php -r 'echo PHP_VERSION;'`).
3. If official packages are too old (below PHP 8.2), it checks whether the [ondrej/php PPA](https://launchpad.net/~ondrej/+archive/ubuntu/php) supports the current Ubuntu codename — using a live HTTP check on the PPA Release file.
4. If the PPA supports the codename, it adds the PPA and installs PHP 8.4.
5. If neither official packages nor the PPA can provide PHP 8.2+, the installer stops with a clear error and suggests using Docker.

The installer **never blindly adds `ppa:ondrej/php`**. Before any `apt update`, it removes stale ondrej/php source files that would cause `apt update` to fail on unsupported Ubuntu releases (e.g. resolute).

### If the native installer cannot run on your Ubuntu version

If the native installer cannot satisfy the PHP version requirement on your specific Ubuntu release, use Docker-based deployment. Docker support is planned for a future release. See the [What's next](#whats-next) section.

## Supply-chain security

The installer (`install.sh`), updater (`update.sh`) and the atomic deploy scripts install their toolchain and dependencies through verified, reproducible steps. No step ever pipes a downloaded script into a shell (`curl … | bash` / `curl … | php`). The pure, testable helpers live in **`scripts/lib/supply-chain-lib.sh`** and are covered by **`tests/supply-chain/run-tests.sh`** (25 mocked scenarios; no root, no network, no real package managers).

### Supported runtime versions (single source of truth)

Declared once in `scripts/lib/supply-chain-lib.sh`:

| Component | Supported |
| --- | --- |
| Ubuntu | 22.04, 24.04, 26.04 |
| PHP | 8.2 – 8.4 |
| PostgreSQL | 14 – 17 |
| Redis | 6 – 7 |
| Composer | 2.2 – 2.x |
| Node.js | 22 (major) |
| npm | 10 – 11 |

The installer **rejects an unsupported runtime** and stops. To proceed anyway (at your own risk) set `ZP_ALLOW_UNSUPPORTED=1`; it is never enabled automatically.

### Composer installer verification

`install.sh` follows the official Composer procedure: it downloads the installer to a private `mktemp` file, fetches the official **SHA-384** signature from `https://composer.github.io/installer.sig`, computes the local hash, and compares them with a constant-time-style check **before executing anything**. On mismatch it aborts with `اعتبار فایل نصب Composer تأیید نشد. عملیات متوقف شد.` and deletes the installer (success or failure). Composer is installed into `/usr/local/bin` and its version verified.

### Node.js repository verification

The NodeSource setup script is **never** piped into `bash`. Instead the installer installs HTTPS/GPG tooling, downloads the official signing key, verifies a real public key was imported, stores it in a dedicated keyring under `/usr/share/keyrings/nodesource.gpg`, configures the apt source with `signed-by=` pinned to the supported major, runs `apt-get update`, installs Node, and verifies `node`/`npm`. On any key/repository failure it aborts with `اعتبار مخزن Node.js تأیید نشد. نصب متوقف شد.`

### Lock-file policy

Production installs are reproducible. Both `composer.lock` and `package-lock.json` **must** be present; the installer runs `composer validate --strict` (lock must match `composer.json`), then `composer install --no-dev --prefer-dist --optimize-autoloader` and `npm ci`. It **never** runs `composer update` or `npm install`, and there is **no fallback** from `npm ci` to `npm install` — a lock mismatch is a hard failure. `--ignore-platform-reqs` is never used.

### Vulnerability audit policy

`composer audit` and `npm audit` run during install and in CI:

- **critical** → always fails the install/CI.
- **high** → fails **unless** an unexpired allowlist entry covers it.
- **moderate / low** → reported, non-blocking.
- Audit failures are never swallowed with `|| true`.

**Advisory exception process** — add a line to **`.zedproxy/audit-allowlist`**:

```
package|advisory|reason|expiry(YYYY-MM-DD)|owner
```

Each entry must name one package + one advisory, a real justification, an ISO expiry date (the entry is ignored on/after that date — malformed/missing dates count as expired), and an owner. No blanket or wildcard entries.

### Exact release pinning & deployed-commit verification

Install from an explicit **tag**, **commit**, or **branch** with `ZP_REF=<tag|sha|branch>`; the installer records the **resolved full commit SHA** (never just a branch name) plus the ref, any exact tag, a build timestamp, and the PHP/Composer/Node/npm versions to `storage/app/release-metadata.json`. The atomic deploy writes the same fields into each release's `RELEASE_MANIFEST.json`. Verify what is live:

```bash
cat /var/www/zedproxy/storage/app/release-metadata.json          # single-dir install
cat /var/www/zedproxy/current/RELEASE_MANIFEST.json              # release-based deploy
git -C /var/www/zedproxy rev-parse HEAD                          # resolved commit
```

Private-repository authentication behaviour is unchanged by any of the above.

### Package source restrictions & privilege model

Composer uses its default trusted repositories and npm the default registry (no arbitrary registries from untrusted environment variables; TLS verification is never disabled; no integrity-disabling flags). Root is used only for apt, filesystem ownership, and service configuration; application files are never made world-writable and shared storage/cache ownership is preserved.

### Unified atomic deployment architecture

Fresh installs, updates, and rollbacks all use **one** release-based layout — a fresh `install.sh` builds it from the very first install (there is no "install directly into `/var/www/zedproxy` then migrate later" step):

```
/var/www/zedproxy/
├── current -> releases/<id>          # Nginx root; Supervisor + scheduler target
├── releases/<id>/                    # one release's application code
└── shared/{.env,storage,persistent}  # state shared across releases
```

Nginx serves `current/public`, the queue worker runs `current/artisan`, and the scheduler runs `current/artisan` — the cutover is **automatic** (no manual step). The installer also writes a non-secret **`/etc/zedproxy/deploy.env`** (`ZPD_BASE`, `ZPD_REPO_URL`, `ZPD_REF`, `ZPD_HEALTH_URL`; never any secret) that every update/rollback/status entrypoint loads.

`zedproxy-update` works **from any directory** — it self-bootstraps by resolving the repository (explicit `ZPD_REPO_URL` → `deploy.env` → active-release origin → legacy origin → the public default `https://github.com/Mhoseinshah1/zed_web.git`; the fallback is **never** `.`), resolving `ZPD_REF` to an exact commit SHA before naming the release (so an id can never end in `-nogit`), fetching the exact source, verifying the deployed HEAD matches, and activating atomically with automatic rollback. Git failures are shown with the real (redacted) cause. A legacy single-directory install is migrated automatically on the first update, keeping a rollback path to the legacy app until the first release is healthy. See **[docs/deployment.md](docs/deployment.md)**.

Re-running `install.sh` on an existing install preserves `.env`, `APP_KEY`, uploads and the database (a DB backup is taken before migrations); on an already-atomic install it repairs infrastructure idempotently and **never** `git reset --hard`s the active release — code changes go through `zedproxy-update`.

### Troubleshooting verification failures

- **`اعتبار فایل نصب Composer تأیید نشد`** — the SHA-384 signature did not match (network tampering, a stale mirror, or an outage of `composer.github.io`). Re-run; if it persists, verify outbound HTTPS to `getcomposer.org` / `composer.github.io`.
- **`اعتبار مخزن Node.js تأیید نشد`** — the NodeSource key could not be downloaded or imported. Check HTTPS access to `deb.nodesource.com` and that `gnupg` is installed.
- **`composer validate` failed / lock mismatch** — `composer.lock` is out of date; regenerate it in development (`composer update`) and commit — **never** on a production server.
- **`npm ci` failed** — `package-lock.json` does not match `package.json`; regenerate and commit it in development.
- **Unsupported runtime** — install a supported version, or set `ZP_ALLOW_UNSUPPORTED=1` to override deliberately.
- **Audit blocked the install** — resolve the advisory (upgrade the dependency) or add a justified, expiring entry to `.zedproxy/audit-allowlist`.

## One-command installation

Download and run (works on all Ubuntu/VPS environments):

```bash
curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh -o /tmp/zedproxy-install.sh
chmod +x /tmp/zedproxy-install.sh
sudo bash /tmp/zedproxy-install.sh
```

Or as a single line:

```bash
curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh -o /tmp/zedproxy-install.sh && chmod +x /tmp/zedproxy-install.sh && sudo bash /tmp/zedproxy-install.sh
```

> **Note:** Do not use `sudo bash <(curl ...)` or `curl ... | sudo bash`. Both formats fail on certain Ubuntu/VPS environments. The download-and-run format above is the only supported method.

The script runs interactively and asks for the following before doing anything:

| Prompt | Default if you press Enter |
|--------|---------------------------|
| Domain (without http/https) | *(required — no default)* |
| Admin email | `admin@DOMAIN` |
| Admin name/username | `zedadmin_RANDOM` (e.g. `zedadmin_a83f21`) |
| Admin password | Strong 24-char random password |
| Install SSL with Let's Encrypt? | `Y` (yes) |
| Use STAGING mode? (if SSL=yes) | `N` (production) |

After all questions are answered, a 3-second countdown lets you cancel with Ctrl+C before anything is installed.

The admin user is created automatically. **The admin password is delivered once, through a secure channel that bypasses the log file** — it is written directly to your terminal (`/dev/tty`) and is **never** written to `/var/log/zedproxy-install.log`, journald, or CI output. If installation fails before the admin user is created, no password is printed. On a safe re-run the installer never re-displays an existing admin password; only a *newly generated* reset password is shown.

The database password is **not** displayed. It is stored securely in `.env` only (اطلاعات اتصال دیتابیس به‌صورت امن در فایل `.env` ذخیره می‌شود و در خروجی یا لاگ نمایش داده نمی‌شود).

All installer output is logged to `/var/log/zedproxy-install.log` (root-only, mode 600, root-only rotation via `/etc/logrotate.d/zedproxy-install`). **The log never contains admin/DB passwords, `APP_KEY`, API/Telegram/payment tokens, or `Authorization` headers** — a redaction layer masks any such value as `[REDACTED]`. If anything goes wrong, check the log first:

```bash
sudo tail -n 120 /var/log/zedproxy-install.log
```

### Non-interactive (unattended) installs

When there is no controlling terminal, the admin password cannot be shown safely on screen, so you must nominate a secure destination file with `ZP_CREDENTIAL_OUTPUT`:

```bash
sudo ZP_CREDENTIAL_OUTPUT=/root/zedproxy-admin-credentials.txt bash install.sh
```

Rules enforced for that path (install aborts *before* creating the admin user if any fails):

- must be an **absolute** path and **not** a symlink;
- parent directory must exist, be a directory, be **owned by root**, and not be group/world-writable;
- must **not** live under `/tmp`, `/var/tmp`, `/dev/shm`, a web root, application `storage/`, `public/`, `uploads/`, or `shared/`;
- the file is created atomically with `umask 077`, mode `600`, owner `root:root`, and is **never overwritten** unless you also pass `ZP_CREDENTIAL_FORCE=1`.

The installer prints only the **path**, never the contents. Read it once, record the password in your password manager, then delete it:

```bash
sudo cat /root/zedproxy-admin-credentials.txt
sudo shred -u /root/zedproxy-admin-credentials.txt   # best-effort; see limitation below
```

If no TTY is available **and** `ZP_CREDENTIAL_OUTPUT` is not set, the installer refuses to continue with:
`امکان نمایش امن اطلاعات ورود وجود ندارد.`

### Sanitizing a log written by an older installer

Installer versions **before** this fix wrote the final summary (including admin/DB passwords) into `/var/log/zedproxy-install.log`. If you upgraded from such a version, scan and clean the old log with the bundled command:

```bash
sudo zedproxy-sanitize-install-log --scan       # report which secret CATEGORIES are present (never the values)
sudo zedproxy-sanitize-install-log --redact      # replace every detected secret with [REDACTED] in place
sudo zedproxy-sanitize-install-log --truncate     # empty the file entirely
sudo zedproxy-sanitize-install-log --redact --backup   # keep a root-only .bak first
```

It requires root, refuses symlinks and non-regular files, preserves the file's owner and mode, never prints a detected value, and is idempotent. Target a different file with `--file /path/to/log`.

> **Limitation — secure erasure is not guaranteed.** `--redact`/`--truncate` rewrites *this file only*. On SSD/flash, copy-on-write filesystems (btrfs/ZFS), LVM/filesystem snapshots, or any off-box/rotated/compressed backup, the original bytes may still be recoverable. **After cleaning the log you must rotate the exposed credentials** (see below).

### Credential rotation after exposure

If credentials may have leaked (old log, a screenshot, a shared support ticket, terminal scrollback), rotate them:

1. **Admin password** — sign in and change it, or run `php artisan zedproxy:create-admin` to set a fresh one (shown once via the secure channel).
2. **Database password** — locate `.env`, choose a new strong password, change it in PostgreSQL (`ALTER ROLE ... WITH PASSWORD ...`), update `DB_PASSWORD` in `.env` atomically, run `php artisan config:clear && php artisan config:cache`, then confirm connectivity with `php artisan migrate:status`. Do this during a maintenance window.
3. Clean the exposed log with `zedproxy-sanitize-install-log`, and check any **copies** (backups, rotated logs, log shippers, screenshots, support tickets, terminal scrollback). Avoid pasting credentials into screenshots.

> The installer/updater **never** auto-rotates a production database password or `APP_KEY` — rotating `APP_KEY` invalidates all existing encrypted values and sessions and must be a deliberate, planned operation.

**Website URL in the final summary is always accurate:** it shows `http://DOMAIN` when SSL is not active and `https://DOMAIN` only when SSL succeeded — never a false https URL.

## Manual installation

### 1. Clone and enter directory

```bash
git clone -b main https://github.com/mhoseinshah1/zed_web.git /var/www/zedproxy
cd /var/www/zedproxy
```

### 2. PostgreSQL setup

```bash
sudo -u postgres psql
```

```sql
CREATE ROLE zedproxy_user LOGIN PASSWORD 'your_strong_password';
CREATE DATABASE zedproxy OWNER zedproxy_user;
GRANT ALL PRIVILEGES ON DATABASE zedproxy TO zedproxy_user;
\q
```

### 3. Redis setup

```bash
sudo apt install redis-server
sudo systemctl enable --now redis-server
redis-cli ping  # should return PONG
```

### 4. Configure environment

```bash
cp .env.example .env
nano .env  # fill in DB_PASSWORD, APP_URL, etc.
```

Required `.env` values:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=zedproxy
DB_USERNAME=zedproxy_user
DB_PASSWORD=your_strong_password

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_CLIENT=predis
```

### 5. Install and build

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan key:generate
php artisan migrate
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 6. Permissions

```bash
sudo chown -R www-data:www-data /var/www/zedproxy
sudo chmod -R 755 /var/www/zedproxy
sudo chmod -R 775 storage bootstrap/cache
sudo chmod 600 .env
```

### 7. Create admin user

Use the dedicated Artisan command (safe to re-run — finds user by email or username, then updates):

```bash
php artisan zedproxy:create-admin \
    --email="admin@yourdomain.com" \
    --username="myadmin" \
    --password="your_secure_password"
```

Then log in at `/zed-admin`.

**Login uses username, not email.** The email is stored for password resets and system notifications but is not used to log in to the admin panel.

Alternatively, the `install.sh` script creates the admin user automatically during installation using the credentials you enter at the prompts.

## Continuous Integration (CI)

All checks run from a single GitHub Actions workflow, **`.github/workflows/ci.yml`** (workflow name **CI**). It triggers on pushes to `main`, `fix/**`, `feat/**`, `chore/**`, and `hardening/**`, on pull requests targeting `main`, and via manual `workflow_dispatch`. Superseded runs on the same ref are auto-cancelled (`concurrency`), and the workflow uses least-privilege permissions (`contents: read` by default).

### Jobs and required status checks

Each job publishes a stable check name suitable for branch protection:

| Required check | Purpose |
| --- | --- |
| **PHP Tests (SQLite)** | Full Laravel test suite on the in-memory SQLite config, then `config:cache` + `route:cache` + `view:cache` to prove the app boots and compiles in production mode. |
| **Integration Tests (PostgreSQL)** | Real PostgreSQL 16 + Redis 7 services. Runs the financial and concurrency-sensitive suites (provisioning, payment, wallet, commission, renewal/add-ons, discount concurrency, order idempotency incl. the forked real-concurrency test) plus scheduler locking and a real-Redis backup-lock test. |
| **Code Style** | `composer validate --strict`, optimized autoload generation (`--strict-psr`), `php -l` syntax lint, and `vendor/bin/pint --test`. |
| **Frontend Build** | `npm ci` (locked) + `npm run build`, and asserts `public/build/manifest.json` exists. |
| **Shell Tests** | `bash -n` on every script, ShellCheck (errors only), the mocked installer/deploy/**supply-chain** helper tests, the **credential-safety tests** (`tests/security/run-tests.sh`: redaction, credential-file validation, secret scan, and a canary-absence assertion), the forbidden-pattern scan (`curl\|bash`/`curl\|php`/`npm install` fallback/`composer update`), lock-file + runtime-version-policy + version-drift validation, and deployment version-metadata generation. No real infrastructure is touched. |
| **Security Audit** | `composer audit` + `npm audit` under **one** allowlist-aware policy (below) shared verbatim with the installer and deploy; a static audit that rejects unsafe credential logging (echoing password variables, logging raw `$BASH_COMMAND`, credential-bearing `curl` headers, secrets as CLI flags, `set -x` in production paths); gitleaks secret scan, committed-`.env` and private-key detection, and best-effort dependency review on PRs. |
| **Migration Validation** | Fresh PostgreSQL migrate, `migrate:status` (no pending), idempotent re-run, PostgreSQL partial-index presence, and the duplicate-data guard. |

> **CI architecture note.** There is exactly **one** authoritative implementation of each check. The supply-chain verification that briefly lived in a separate `supply-chain.yml` (PR #63, created before PR #62 merged) has been folded into **Shell Tests** (helper tests, forbidden-pattern scan, lock-file/version checks, metadata generation) and **Security Audit** (the unified audit policy). `supply-chain.yml` was removed to eliminate duplicate jobs; no coverage was lost.

### Supported runtime versions

The **authoritative source of truth** is `scripts/lib/supply-chain-lib.sh` (the `ZSC_*` constants). CI, the installer, the deploy scripts, and this README must agree with it — the *Shell Tests* job fails on drift (it checks `ci.yml`'s `PHP_VERSION`/`NODE_VERSION` and `install.sh`'s `NODE_VERSION` against the policy, and that this table's PHP range is present).

| Component | Supported policy | Used in CI |
| --- | --- | --- |
| Ubuntu | 22.04, 24.04, 26.04 | — |
| PHP | 8.2 – 8.4 | 8.3 |
| PostgreSQL | 14 – 17 | 16 |
| Redis | 6 – 7 | 7 |
| Composer | 2.2 – 2.x | 2.x |
| Node.js | 22 | 22 |
| npm | 10 – 11 | bundled with Node 22 |

These match production; the installer provisions the same major versions and rejects unsupported ones unless `ZP_ALLOW_UNSUPPORTED=1`.

### Which tests require PostgreSQL

Tests that validate real locks, partial unique indexes, deadlocks, or concurrent connections **must** run on PostgreSQL and are exercised only in *Integration Tests (PostgreSQL)*:

- `OrderIdempotencyPgTest`, `DiscountConcurrencyPgTest`, `ProvisioningConcurrencyTest` (forked, multi-connection; skipped on SQLite/without `pcntl`).
- The partial unique indexes (`orders_user_fingerprint_unpaid_unique`, `user_services_order_id_unique`) and the migration duplicate guards.

The rest of the suite runs on SQLite in *PHP Tests (SQLite)*.

### Local commands matching CI

```bash
# PHP Tests (SQLite)
composer install --no-interaction --prefer-dist --no-progress
php artisan test
php artisan config:cache && php artisan route:cache && php artisan view:cache

# Code Style
composer validate --strict --no-check-publish
composer dump-autoload --optimize --strict-psr
vendor/bin/pint --test        # add no flag to auto-fix

# Frontend Build
npm ci && npm run build

# Shell Tests
bash tests/installer/run-tests.sh
bash tests/deploy/run-tests.sh
bash tests/supply-chain/run-tests.sh

# Security Audit (the authoritative gate applies the allowlist-aware policy;
# these are quick local approximations)
composer audit --abandoned=report
npm audit --audit-level=high

# Integration / Migration (needs a local Postgres + Redis)
DB_CONNECTION=pgsql php artisan migrate --force
DB_CONNECTION=pgsql php artisan test --testsuite=Feature \
  --filter='OrderIdempotencyPgTest|DiscountConcurrencyPgTest|ProvisioningConcurrencyTest'
```

### Audit / severity policy

There is **one** audit policy, implemented once in `scripts/lib/supply-chain-lib.sh` (`zsc_audit_decision` + `zsc_allowlist_entry_active`) and applied identically by the **installer** (`install.sh`), the **atomic deploy** (`scripts/deploy/deploy.sh`), and CI's **Security Audit**. CI can never pass an advisory the installer would reject, or vice-versa.

- **Critical** (composer or npm) → **fail** (never allowlistable).
- **High** (composer or npm) → **fail** *unless* an unexpired allowlist entry covers it.
- **Moderate / low** → reported, non-blocking.
- **Allowlist** — `.zedproxy/audit-allowlist`, one entry per line: `package|advisory|reason|expiry(YYYY-MM-DD)|owner`. An **expired** or **malformed/missing-expiry** entry does **not** suppress the finding; no blanket/wildcard entries. Abandoned composer packages are ignored (not a vulnerability).
- **Secrets:** gitleaks runs with `--redact` so discovered values are never printed; a tracked real `.env` or an embedded private key fails the job.
- **Dependency review:** GitHub's Dependency Review augments the audits on PRs but is best-effort (`continue-on-error`) because it needs the Dependency Graph API, which is not available on every private plan — the `composer audit` + `npm audit` gate remains authoritative.

### Caching

Composer and npm **download** caches are keyed on `composer.lock` / `package-lock.json` hashes. Application state that could contain secrets — `.env`, `vendor` across incompatible locks, database directories, Laravel caches, and runtime `storage/` — is never cached.

### Action pinning

Official/first-party actions are pinned to stable major versions (`actions/checkout@v4`, `actions/cache@v4`, `actions/setup-node@v4`, `actions/upload-artifact@v4`, `shivammathur/setup-php@v2`, `actions/dependency-review-action@v4`). gitleaks is installed from a version-pinned release tarball rather than a third-party action. **Update policy:** bump majors deliberately in a dedicated PR and let the full pipeline validate them before merge.

### Rerunning a failed workflow

- From the PR/commit **Checks** tab, open the run and choose **Re-run failed jobs** (or **Re-run all jobs**).
- Or push a new commit / use the **Run workflow** button (`workflow_dispatch`) on the Actions tab.
- CLI: `gh run rerun <run-id> --failed`.

Never make a required job `continue-on-error` to get a green result — inspect the logs and fix the cause.

## Recommended branch protection for `main`

Configure these in **Settings → Branches → Branch protection rules** (requires admin; this repository's protection settings are **not** modified by CI):

- **Require a pull request before merging**, with at least **1 approval** (raise for shared/production repos).
- **Require status checks to pass**, selecting all seven: `PHP Tests (SQLite)`, `Integration Tests (PostgreSQL)`, `Code Style`, `Frontend Build`, `Shell Tests`, `Security Audit`, `Migration Validation` — and **require branches to be up to date**.
- **Require conversation resolution** before merging.
- **Do not allow force pushes** and **do not allow deletions** of `main`.
- **Optionally require signed commits.**
- **No direct production deployment from an untested commit** — deploy only from a `main` commit whose CI is green (see the deployment scripts under `scripts/deploy/`).

## Database backup

### Create a backup

```bash
bash scripts/backup.sh
```

Backup files are saved to `storage/app/backups/` with timestamps:

```
zedproxy_2026-06-27_03-00.dump
```

### Automate daily backups

Backups are driven by the **Laravel scheduler** (`zedproxy:backup --scheduled`),
configured from the admin panel — there is no separate backup cron. Make sure
the scheduler cron below is installed (the installer does this automatically);
everything else (backups, Telegram reports, panel health, Marzban sync) runs
through it. Backups older than the configured retention are removed automatically.

## Scheduler (production-critical)

The single supported scheduling method is one cron entry that runs the Laravel
scheduler every minute. `install.sh` installs it idempotently (re-running never
creates duplicates) and removes the legacy backup cron:

```bash
echo "* * * * * www-data cd /var/www/zedproxy && php artisan schedule:run >> /var/log/zedproxy-scheduler.log 2>&1" \
    | sudo tee /etc/cron.d/zedproxy-scheduler
```

- **Timezone:** schedule times use `APP_TIMEZONE` (default `UTC`). Keep the
  server clock/cron in the same timezone to avoid surprises.
- **Overlap + Redis outages:** `withoutOverlapping()` locks use the **file**
  cache store (`SCHEDULER_LOCK_STORE=file`, wired to `cache.schedule_store`), so
  overlap prevention keeps working even if Redis is down.
- **Log rotation:** `/etc/logrotate.d/zedproxy-scheduler` rotates the log.

### Verify the scheduler is running

```bash
php artisan schedule:list              # list registered tasks
php artisan zedproxy:scheduler-status  # last successful heartbeat (exit 0 healthy)
```

A heartbeat is recorded every minute; the admin **وضعیت سیستم** page and
`php artisan zedproxy:health` also show the scheduler status. If it reports
"زمان‌بندی وظایف به‌درستی اجرا نمی‌شود." the cron above is not firing.

## Restore from backup

On the new server, after completing steps 1-6 above (without running migrations):

```bash
# Restore from a .dump file
PGPASSWORD=your_db_password pg_restore \
    -h 127.0.0.1 \
    -U zedproxy_user \
    -d zedproxy \
    --clean \
    --if-exists \
    /path/to/zedproxy_2026-06-27_03-00.dump
```

Then run:

```bash
php artisan config:cache
php artisan route:cache
```

## Health check

```bash
curl https://yourdomain.com/health
```

Expected response:

```json
{
    "status": "ok",
    "app": true,
    "database": true,
    "redis": true,
    "migrations": true,
    "storage": true
}
```

`/health` and `/health/live` are **stateless**: they are registered outside the
`web` middleware group (`routes/health.php` via `bootstrap/app.php`), so they
never start a session or set session / `XSRF-TOKEN` cookies — the atomic deployer
hits them every few seconds through the loopback vhost, and uptime monitors hit
them constantly. Only rate limiting and the non-indexable `X-Robots-Tag` header
apply, and the payload is safe booleans only (never exception text, paths, or
secrets). During a deployment these endpoints are validated over the internal
loopback vhost (`http://127.0.0.1:18080` → `current/public`), independent of
Cloudflare / public TLS. See `docs/deployment.md` → *Two-phase readiness*.

## Useful commands

```bash
# Clear all caches
php artisan optimize:clear

# Rebuild caches (production)
php artisan optimize

# Run queue worker manually
php artisan queue:work redis --tries=3

# Check failed jobs
php artisan queue:failed

# View logs
tail -f storage/logs/laravel.log

# Artisan tinker (REPL)
php artisan tinker

# Run specific migration
php artisan migrate --step

# Rollback last migration
php artisan migrate:rollback
```

## Queue workers (Supervisor)

The `install.sh` configures Supervisor to run 2 queue workers. To manage:

```bash
sudo supervisorctl status
sudo supervisorctl restart zedproxy-worker:*
sudo supervisorctl stop zedproxy-worker:*
```

## Troubleshooting

### Installation log

Every installer run writes to `/var/log/zedproxy-install.log` (root-only, 600):

```bash
sudo tail -n 120 /var/log/zedproxy-install.log
```

If a command fails, the log shows the exact line number and command.

### Fresh installation vs. safe re-run

`install.sh` runs in one of two modes, chosen automatically. It considers the
target an **existing installation** when `/var/www/zedproxy` contains all of
`.env`, `artisan`, and `composer.json`. Otherwise it treats the run as a
**fresh installation**. On a re-run you will see:

```
نصب قبلی ZedProxy شناسایی شد.
مقادیر APP_KEY، اطلاعات دیتابیس و تنظیمات فعلی حفظ خواهند شد.
```

**Fresh installation** — creates the PostgreSQL database + role with a freshly
generated password, writes a new `.env`, runs `php artisan key:generate --force`
to mint `APP_KEY`, runs migrations, and creates the initial admin user. The
generated admin and database passwords are shown once in the final summary.

**Safe re-run / repair / upgrade** — designed to be run repeatedly on a live
production server without data loss. It:

- **Never overwrites `.env`** — `APP_KEY`, `DB_PASSWORD`, and every custom
  variable (Redis, mail, payment, Telegram, panel, storage, queue, app) are
  preserved exactly.
- **Never rotates the PostgreSQL role password** and never drops/recreates the
  database. It reads the credentials from the existing `.env` and only tests
  that the connection works before continuing.
- **Never runs `key:generate --force`.** `APP_KEY` is only generated when it is
  genuinely empty, and then without `--force` (written directly, never rotated).
- **Never auto-creates a second admin** and never resets the admin password.
  An optional interactive reset is offered (`آیا رمز عبور مدیر فعلی بازنشانی
  شود؟ [y/N]`) that defaults to **No**.
- Updates code with `git fetch` + `git reset --hard origin/main` + `git clean
  -fd` (**without `-x`**), so gitignored runtime files (`.env`, `storage/`,
  uploaded media, `public/storage`) are never touched. Locally-modified tracked
  files and untracked non-ignored files are backed up first.
- After migrations, validates that existing encrypted secrets still decrypt
  with the current `APP_KEY` (`php artisan zedproxy:verify-encryption`). If
  decryption fails, the run is **not** reported as successful — it rolls back.

#### What is backed up (re-run)

Everything is timestamped under `/var/backups/zedproxy/reinstall/YYYYMMDD_HHMMSS/`
(directories `700`, files `600`):

| Item | Path |
| --- | --- |
| `.env` (before any change) | `…/reinstall/<ts>/.env` |
| Database dump (before migrations) | `…/reinstall/<ts>/<db>_<ts>.dump` (custom `pg_dump -Fc`) |
| Locally-modified/untracked files | `…/reinstall/<ts>/local_changes/…` |
| Previous deployed commit SHA | printed in the summary + recorded for rollback |

#### What is preserved (re-run)

`.env` and `APP_KEY`, the database and its password, the admin account and
password, all uploaded files and the `storage/` tree, and every custom
environment variable.

#### What triggers a rollback

Any of these during a re-run restores the backed-up `.env`, resets the code to
the previous commit, brings the app out of maintenance mode, and prints DB
restore instructions (uploads/storage are never modified, `APP_KEY` is never
changed):

- `.env` backup could not be created
- database backup (`pg_dump`) failed
- database migrations failed
- **encrypted-secret validation failed** — e.g. a wrong/rotated `APP_KEY`, an
  invalid MAC, or corrupted ciphertext:

  ```
  خطا در رمزگشایی اطلاعات حساس. APP_KEY یا اطلاعات رمزگذاری‌شده معتبر نیستند.
  عملیات متوقف و تنظیمات قبلی بازیابی شد.
  ```

#### Restoring a database backup manually

```bash
# Credentials come from /var/www/zedproxy/.env (DB_DATABASE, DB_USERNAME, …).
PGPASSWORD='<DB_PASSWORD>' pg_restore \
    -h 127.0.0.1 -p 5432 -U <DB_USERNAME> -d <DB_DATABASE> \
    --clean --if-exists \
    /var/backups/zedproxy/reinstall/<timestamp>/<db>_<timestamp>.dump
```

To roll back code manually: `cd /var/www/zedproxy && git reset --hard <previous-commit>`.

Other re-run behavior:

- Nginx config is only rewritten if no certbot-managed SSL blocks exist —
  existing SSL config is preserved.
- A valid existing Let's Encrypt certificate is reused; certbot does not request
  a new one.

> **Note:** the equivalent code-only update flow is `update.sh` (`zedproxy-update`),
> which performs the same backup/maintenance/migrate/restart steps without
> re-touching the system packages.

#### Verifying safety yourself

The installer's decision logic (existing-install detection, `APP_KEY`
validation, dotenv parsing, `.env`/local-change backups) lives in the
sourceable, side-effect-free library `scripts/lib/installer-lib.sh` and is
covered by shell tests:

```bash
bash tests/installer/run-tests.sh
```

The encrypted-secret check is covered by `tests/Feature/VerifyEncryptionCommandTest.php`.

### Let's Encrypt rate limit

```bash
# Check rate limit status in the install log
sudo grep -i "rate limit\|too many" /var/log/zedproxy-install.log

# After 168 hours (7 days), run manually:
certbot --nginx -d yourdomain.com -m admin@yourdomain.com \
    --non-interactive --agree-tos --redirect --no-eff-email
```

### Database connection refused

```bash
sudo systemctl status postgresql
sudo -u postgres psql -c "\l"
```

### Redis connection refused

```bash
sudo systemctl status redis-server
redis-cli ping
```

### 500 errors

```bash
tail -50 storage/logs/laravel.log
php artisan config:clear
php artisan cache:clear
```

### Nginx 502 Bad Gateway

```bash
# Detect the installed PHP-FPM service name first
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo systemctl status php${PHP_VERSION}-fpm
sudo nginx -t
sudo journalctl -u nginx -n 50
```

### Permission errors

```bash
sudo chown -R www-data:www-data /var/www/zedproxy
sudo chmod -R 775 storage bootstrap/cache
```

### Admin panel not loading

Re-run the admin creation command to ensure `is_admin = true` and the password is correctly hashed:

```bash
php artisan zedproxy:create-admin \
    --email="admin@example.com" \
    --username="myadmin" \
    --password="your_password"
```

The admin panel is at `/zed-admin`, not `/admin`. Login uses **username**, not email.

## Diagnostics

Run these commands to gather system state before reporting an issue:

```bash
lsb_release -a
cat /etc/os-release
php -v
nginx -v
psql --version
redis-server --version
```

## Branch convention

All production installation and deployment commands must use the **`main`** branch.

| Purpose | Command |
|---------|---------|
| Install | `curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh -o /tmp/zedproxy-install.sh && chmod +x /tmp/zedproxy-install.sh && sudo bash /tmp/zedproxy-install.sh` |
| Clone | `git clone -b main https://github.com/mhoseinshah1/zed_web.git` |
| Update | `git pull origin main` |

Do not deploy from `master`, `develop`, `staging`, or any other branch without explicit testing.

## Deployment notes

### SSL (HTTPS)

The installer prompts you to install a free SSL certificate with Let's Encrypt during setup. The default answer is **yes**.

**What the installer does automatically:**

1. Asks: `Install free SSL certificate with Let's Encrypt? [Y/n]` — press Enter to accept.
2. Asks: `Use Let's Encrypt STAGING mode? [y/N]` — press Enter for production (recommended).
3. Installs `certbot` and `python3-certbot-nginx` (non-interactive).
4. **Checks for an existing valid certificate** — if one exists for the domain, reuses it and skips requesting a new one (avoids rate limits on re-runs).
5. Checks DNS before running certbot — compares the domain's A record with the server's public IP.
6. Includes `www.DOMAIN` in the certificate only if `www.DOMAIN` also resolves to this server.
7. Runs certbot after Nginx is configured and the HTTP health check passes.
8. On success: updates `APP_URL` in `.env` to `https://DOMAIN`, rebuilds config cache, verifies HTTPS health.
9. On failure: does not remove the working HTTP site. Prints a manual certbot command. The final summary shows `http://DOMAIN`.

**The final summary always shows the correct URL.** If SSL failed or was skipped, the summary shows `http://DOMAIN`. It never shows `https://` when SSL is not active.

#### Let's Encrypt rate limits

If certbot fails with "too many certificates" or "rate limit", the installer:
- Does **not** retry automatically
- Does **not** fail the website installation
- Shows a clear warning with the reason
- Keeps HTTP working

Once the rate limit window (168 hours / 7 days) has passed, run certbot manually:

```bash
certbot --nginx -d yourdomain.com -m admin@yourdomain.com \
    --non-interactive --agree-tos --redirect --no-eff-email
```

#### Staging mode (for testing)

If you need to test the SSL flow without using production rate limits, choose `y` at the staging prompt. The certificate will not be trusted by browsers but the full certbot flow runs. **Always use production for real installs.**

#### DNS must point to this server before SSL can be issued

If DNS is not ready at install time, choose `n` at the SSL prompt and run certbot manually once DNS propagates:

```bash
certbot --nginx -d yourdomain.com -m admin@yourdomain.com \
    --non-interactive --agree-tos --redirect --no-eff-email
```

To add `www` to an existing certificate:

```bash
certbot --nginx -d yourdomain.com -d www.yourdomain.com \
    -m admin@yourdomain.com --non-interactive --agree-tos --redirect --no-eff-email
```

#### Verify SSL after installation

```bash
curl -I https://yourdomain.com/health
# Expected: HTTP/2 200
```

### After code update

Use the dedicated update script — it handles backups, maintenance mode, migrations, and service restarts safely:

```bash
zedproxy-update
```

Or download and run the latest version directly:

```bash
curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/update.sh -o /tmp/zedproxy-update.sh && chmod +x /tmp/zedproxy-update.sh && sudo bash /tmp/zedproxy-update.sh
```

The update script is logged to `/var/log/zedproxy-update.log`.

### Files to preserve when moving to another server

| Path | Why |
|------|-----|
| `.env` | App secrets and DB credentials |
| `storage/app/` | Uploads, backups, local files |
| `storage/logs/` | Application logs |
| PostgreSQL backup | All database data |

Do NOT copy `vendor/`, `node_modules/`, or `public/build/` - regenerate these with `composer install` and `npm run build`.

## Admin panel

Visit `/zed-admin` after creating an admin user.

**Login:** Use your **username** (not email) and password. The login URL is `/zed-admin/login`.

| Field | Value |
|-------|-------|
| Admin panel URL | `https://DOMAIN/zed-admin` |
| Login URL | `https://DOMAIN/zed-admin/login` |
| Login field | **Username** (not email) |

Email is stored on the user record for password resets and system contact but is **not** entered on the admin login form.

Current sections:

| Section | URL | Description |
|---------|-----|-------------|
| **Users** | `/zed-admin/users` | Manage users, view wallet balance, manually credit/debit wallet |
| **Plans** | `/zed-admin/plans` | Create/edit VPN plans with price, traffic, duration, features |
| **Features** | `/zed-admin/features` | Manage plan features (e.g. "بدون محدودیت سرعت") |
| **Locations** | `/zed-admin/locations` | Manage server locations with flag emoji |
| **Site Texts** | `/zed-admin/site-texts` | Edit all homepage/footer/legal texts stored in DB |
| **Orders** | `/zed-admin/orders` | View and manage orders; quick actions: mark processing, mark completed, cancel |
| **Transactions** | `/zed-admin/payment-transactions` | Approve or reject submitted manual payments with admin note |
| **Wallet Transactions** | `/zed-admin/wallet-transactions` | Read-only ledger of all wallet credits and debits |
| **Payment Methods** | `/zed-admin/payment-methods` | Manage payment methods (wallet, crypto, stars, rial) |
| **System Status** | `/zed-admin/system-status` | Live health checks for DB, Redis, storage, queue |
| **Services** | `/zed-admin/user-services` | View all user services; activate, disable, cancel, retry Marzban provisioning, sync usage |
| **VPN Panels** | `/zed-admin/vpn-panels` | Manage Marzban panels — add credentials, test connection, set default, open API docs |
| **VPN Inbounds** | `/zed-admin/vpn-inbounds` | Manage inbound tags linked to panels |

### Content that survives updates

All site content lives in the database — `update.sh` seeds only **missing** defaults and **never overwrites** admin-edited values:

| Content | Table | Admin URL |
|---------|-------|-----------|
| Homepage hero, features section, CTA | `site_texts` | `/zed-admin/site-texts` |
| VPN plan names, prices, descriptions | `plans` | `/zed-admin/plans` |
| Plan feature titles | `features` | `/zed-admin/features` |
| Server location names | `locations` | `/zed-admin/locations` |
| Footer text, legal pages | `site_texts` | `/zed-admin/site-texts` |
| Payment method titles, instructions, accounts | `payment_methods` | `/zed-admin/payment-methods` |

> **Note:** `update.sh` never resets admin passwords, site texts, plans, features, or locations.

### `site_setting()` helper

Use `site_setting('key', 'default')` anywhere in Blade or PHP to read a site text:

```php
{{ site_setting('homepage.hero.title', 'Default title') }}
```

Values are cached for 1 hour and auto-invalidated when the admin saves a change.

## Marzban integration

ZedProxy integrates with [Marzban](https://github.com/Gozargah/Marzban) to automatically create VPN users after payment.

### API docs reference

Marzban exposes Swagger UI at `/docs` and OpenAPI JSON at `/openapi.json` when `DOCS=True` is set.

Example panel used during development:
- `base_url`: `https://panel.staygreen.top`
- `api_docs_url`: `https://panel.staygreen.top/docs`

### Endpoints used (confirmed from Marzban source)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/api/admin/token` | OAuth2 login — form data `username`+`password` → `{access_token, token_type}` |
| `GET`  | `/api/system` | System stats — used to verify connection |
| `GET`  | `/api/inbounds` | List available inbounds by protocol |
| `POST` | `/api/user` | Create a new VPN user |
| `GET`  | `/api/user/{username}` | Get user details and subscription URL |
| `PUT`  | `/api/user/{username}` | Update user (traffic, expiry, status) |
| `DELETE` | `/api/user/{username}` | Delete user |
| `POST` | `/api/user/{username}/reset` | Reset traffic usage to zero |
| `POST` | `/api/user/{username}/revoke_sub` | Revoke subscription token (generates new URL) |

Auth: `Authorization: Bearer {access_token}` on all endpoints except `/api/admin/token`.

### Setting up a Marzban panel

1. Go to `/zed-admin/vpn-panels` → click **New Panel**
2. Fill in:
   - **Name**: e.g. `Main Marzban`
   - **Type**: `Marzban`
   - **Base URL**: `https://your-panel-domain:port`
   - **API Docs URL**: `https://your-panel-domain:port/docs`
   - **Username**: Marzban admin username
   - **Password**: Marzban admin password (stored encrypted)
   - **Active**: toggle on
   - **Default**: toggle on (services will auto-provision here)
3. Save, then click **تست اتصال** (Test Connection) in the table actions

### Inbounds

ZedProxy provisions users with `proxies: {vless: {}}` and does **not** send an `inbounds` key in the create/update payload. Marzban automatically assigns all available inbounds when the `inbounds` key is absent — no manual inbound configuration is required.

### How automatic provisioning works

1. User pays an order (wallet or admin-approved manual payment)
2. `PaymentService` calls `ServiceProvisioner::createFromOrder()` — idempotent
3. If a default active Marzban panel exists, `ProvisionMarzbanServiceJob` is dispatched to the queue
4. The job:
   - If `remote_username` is already set (retry scenario): GET user first; if found, update it
   - Otherwise creates the user — if Marzban returns 409 (duplicate), GET existing then update
   - Username format: `zpx_{user_id}_{service_id}_{random5}` (max 32 chars)
   - Sets `data_limit` from `traffic_total_gb` (bytes), `expire` from `expires_at` (Unix timestamp)
   - Saves `subscription_url` from the Marzban response to `user_services.subscription_link`
   - Saves `links[0]` as `config_link`, normalised traffic as `traffic_used_gb`
   - Sets service `status = active`, `provision_status = provisioned`
5. If no default panel exists, the service stays `provision_status = manual_required`

### Username format

```
zpx_{user_id}_{service_id}_{random5}
```

Examples: `zpx_1_42_ab3cd`, `zpx_7_103_xy9zw`

Rules: lowercase alphanumeric + underscores, max 32 characters, matches Marzban's `^[a-zA-Z0-9@_.+-]+$` validation.

### Subscription link

The Marzban `UserResponse` includes a `subscription_url` field (e.g. `https://panel.example.com/sub/TOKEN/`). ZedProxy saves this directly to `user_services.subscription_link`. It is shown to the user in `/dashboard/services/{service}` with a copy button and an inline SVG QR code (server-side, no CDN dependency).

The first link from `links[]` is saved as `config_link` (direct VLESS/VMess config). The user also sees this on their service detail page with its own QR code.

### QR code generation

ZedProxy uses [`simplesoftwareio/simple-qrcode`](https://www.simplesoftwareio.com/simple-qrcode) to generate inline SVG QR codes on the server. No JavaScript QR library or CDN is required. The QR code updates automatically when an admin uses **تغییر لینک اشتراک** (Revoke Subscription) — the new URL is saved to the database and the next page load shows the updated QR.

- **User-facing**: `/dashboard/services/{service}` — subscription link QR + config link QR (if set)
- **Admin-facing**: **مشاهده بارکد لینک اشتراک** action in UserServiceResource table — opens a Filament modal with subscription QR and optional config QR

### What the user sees

- **Active service with subscription link**: QR code + copy button for the subscription URL, plus config link QR if set
- **Pending/failed service**: Persian message: "سرویس شما هنوز آماده نشده است. در صورت طولانی شدن، با پشتیبانی تماس بگیرید."
- **No subscription link yet**: "لینک اشتراک هنوز آماده نشده است." message

Users can only see their **own** service pages. Accessing another user's service detail returns 403.

### User self-service Marzban actions

The service detail page at `/dashboard/services/{service}` includes a **مدیریت سرویس** (Service Management) section with user-facing actions. All actions are POST routes under `auth` middleware with global 30 req/min throttle.

| Route | Action | Persian label | Enabled by default |
|-------|--------|--------------|-------------------|
| `POST /dashboard/services/{service}/sync` | Sync from Marzban | بروزرسانی وضعیت سرویس | ✅ Yes |
| `POST /dashboard/services/{service}/revoke-subscription` | Revoke subscription | تغییر لینک اشتراک | ✅ Yes |
| `POST /dashboard/services/{service}/reset-traffic` | Reset traffic | ریست ترافیک | ❌ No (admin must enable) |
| `POST /dashboard/services/{service}/disable` | Disable service | غیرفعال‌سازی سرویس | ❌ No (admin must enable) |
| `POST /dashboard/services/{service}/enable` | Enable service | فعال‌سازی سرویس | ❌ No (admin must enable) |

**Revoke subscription** is additionally rate-limited to **once per 10 minutes per service** (configurable via `services.revoke_subscription_cooldown_seconds` setting). After the cooldown, the user sees: "برای تغییر مجدد لینک اشتراک کمی بعد دوباره تلاش کنید."

When a user revokes their subscription:
1. `POST /api/user/{username}/revoke_sub` is called on Marzban
2. Marzban generates a new subscription token
3. The new `subscription_url` is saved to `user_services.subscription_link`
4. The new `links[0]` is saved to `user_services.config_link`
5. The QR code on the page updates automatically on next load

Actions are only shown for **active services** with a `remote_username`. Inactive/pending services see: "این عملیات فقط برای سرویس‌های فعال قابل انجام است."

The enable button is shown for **disabled** services (only when the admin setting allows it).

### Admin settings for user self-service (SiteText, group: `services`)

| Key | Default | Label |
|-----|---------|-------|
| `services.allow_user_revoke_subscription` | `true` | اجازه تغییر لینک اشتراک توسط کاربر |
| `services.allow_user_sync_service` | `true` | اجازه بروزرسانی وضعیت سرویس توسط کاربر |
| `services.allow_user_reset_traffic` | `false` | اجازه ریست ترافیک توسط کاربر |
| `services.allow_user_disable_service` | `false` | اجازه غیرفعال‌سازی سرویس توسط کاربر |
| `services.allow_user_enable_service` | `false` | اجازه فعال‌سازی سرویس توسط کاربر |
| `services.revoke_subscription_cooldown_seconds` | `600` | فاصله زمانی تغییر لینک اشتراک (ثانیه) |

Settings are seeded via `ServiceSettingsSeeder` using `firstOrCreate` — admin-edited values are **never overwritten** by future deploys.

To change a setting: open `/zed-admin/site-texts` and edit the relevant key, or update the value directly in the database.

### What remains admin-only

The following actions are **never** exposed to users:

- **حذف از مرزبان** (Delete Remote User) — irreversible, admin-only
- **ساخت دوباره در مرزبان** (Recreate Remote User) — admin-only
- **پاک کردن لینک‌های محلی** (Clear Local Links) — admin-only
- Admin notes, provision logs, panel credentials, VPN panel details
- Any route modification or username change

### Admin actions on UserServiceResource

The admin panel at `/zed-admin/user-services` has the full action set (admin-only):

| Action | Description |
|--------|-------------|
| **ساخت دوباره در مرزبان** (Recreate) | Runs `ProvisionMarzbanServiceJob` synchronously; creates or updates the Marzban user; visible when provision_status is failed, manual_required, or skipped |
| **همگام‌سازی از Marzban** (Sync) | Calls `GET /api/user/{username}`, updates traffic, subscription link, config link, and expiry; visible when remote_username is set |
| **ریست ترافیک** (Reset Traffic) | Calls `POST /api/user/{username}/reset`; resets used traffic on panel and locally to 0; visible when remote_username is set |
| **تغییر لینک اشتراک** (Revoke Subscription) | Calls `POST /api/user/{username}/revoke_sub`; Marzban generates a new subscription token; saves new URL locally; QR updates on next page load |
| **غیرفعال کردن** (Disable) | Calls `PUT /api/user/{username}` with `{status: disabled}`; sets local status to disabled; visible when service is active |
| **فعال کردن** (Enable) | Calls `PUT /api/user/{username}` with `{status: active}`; sets local status to active; visible when service is disabled |
| **حذف از مرزبان** (Delete Remote) | Calls `DELETE /api/user/{username}`; removes user from Marzban panel; nulls subscription_link and config_link; sets status to cancelled; local service record is kept |
| **پاک کردن لینک‌های محلی** (Clear Local Links) | Nulls subscription_link and config_link in local DB only; no Marzban API call; useful after manual panel changes |
| **مشاهده بارکد لینک اشتراک** (View QR) | Opens a Filament modal with the subscription QR code (220px SVG) and optional config QR; visible when subscription_link is set |

### What happens if provisioning fails

- Service `provision_status` is set to `failed`
- Error message is saved to `admin_notes`
- A `VpnServiceProvisionLog` entry is created with `status = failed`
- The order is **not** affected — payment remains approved
- Admin can click **تلاش مجدد Marzban** from the service table to retry

### Queue worker

Provisioning jobs run via the queue worker (Supervisor). On production:

```bash
sudo supervisorctl status zedproxy-worker:*
sudo supervisorctl restart zedproxy-worker:*
```

### Security notes

- Marzban admin password is stored encrypted using Laravel's `encrypted` cast (`APP_KEY` is the secret)
- Access tokens are cached in Redis only — never stored in plaintext in the database
- Token is rotated on 401 response (retry-once logic)
- Users cannot see panel credentials or admin-only API data
- The `subscription_url` is the only Marzban data exposed to regular users

Upcoming sections (in future development phases):

- Payment gateway — Rial/crypto integration
- Telegram bot — admin reports and notifications
- Ticket system — support tickets
- Monitoring — live server status
- Renew / extra traffic — update Marzban user after renewal

## Email (SMTP) configuration and email-OTP verification

Outbound mail defaults to `MAIL_MAILER=log` (messages go to the log — nothing
is delivered). Email verification sends a 6-digit OTP; it treats the `log`
and `array` mailers as **unconfigured** in production, so "required at
registration" cannot be enabled until a real transport works.

**OTP delivery supports exactly ONE effective delivery leaf.** The delivery
pipeline's timing invariant is strict — per-operation SMTP timeout ≤ 20 s,
whole-job deadline 240 s, per-user lock TTL 270 s, queue redelivery horizon
300 s — and it budgets for a single complete transport exchange. A
`failover`/`roundrobin` mailer that resolves to **more than one** leaf could
chain several full exchanges in one attempt, overrunning the job deadline and
the lock TTL mid-send (worker killed during SMTP I/O, queue redelivery while
a previous worker is still sending, duplicate OTP emails). Multi-leaf graphs
are therefore rejected for OTP delivery: the mailer counts as unconfigured,
required mode cannot be enabled, the test action refuses to certify it, and
no OTP job is queued through it. The default `failover` (smtp → log) is
**not** suitable for production OTP — use `MAIL_MAILER=smtp` for the current
deployment. A composite that resolves to exactly one leaf is accepted.

1. Edit the server's `.env` (SMTP credentials live ONLY here — never in the
   database or the admin panel):

   ```
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.example.com
   MAIL_PORT=587
   MAIL_SCHEME=null
   MAIL_USERNAME=your-user
   MAIL_PASSWORD=your-pass
   MAIL_FROM_ADDRESS=noreply@yourdomain.com
   MAIL_FROM_NAME="ZedProxy"
   MAIL_TIMEOUT=10
   ```

2. Apply the change (config is cached in production):

   ```
   php artisan optimize:clear
   php artisan config:cache
   sudo supervisorctl restart zedproxy-worker:*   # queue workers pick up the new config
   ```

3. In the admin panel → «تنظیمات ایمیل و تایید ایمیل» use **ارسال ایمیل تست**
   to confirm real delivery, then enable verification (and, if desired,
   make it required at registration).

Existing users are grandfathered by a one-time migration (their
`email_verified_at` is backfilled to their `created_at`), so enabling
required verification never locks out accounts that existed before the
feature shipped. The installer preserves your mail configuration on re-runs
and never prints or logs `MAIL_PASSWORD`.

## Updating ZedProxy

The `update.sh` script performs a safe, zero-data-loss update of a running ZedProxy installation.

Every update is **self-healing**: operational configuration (Scheduler cron,
Supervisor worker, Nginx root, command wrappers, loopback health vhost) is
snapshotted and reconciled to the canonical atomic layout on every activation —
a partially migrated server repairs itself on the next normal update, and a
healthy pre-manifest release is *adopted* so status/rollback keep working. Two
operator commands support this: `zedproxy-doctor` (comprehensive read-only,
redacted diagnostics; `--bundle` writes a root-only archive) and
`zedproxy-deploy-repair` (`--scan` read-only; `--apply` repairs with backups —
never migrations, `.env`, credentials, or `APP_KEY`). See
`docs/deployment.md` for the full architecture.

### One-command update

```bash
curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/update.sh -o /tmp/zedproxy-update.sh && chmod +x /tmp/zedproxy-update.sh && sudo bash /tmp/zedproxy-update.sh
```

Or use the shortcut installed automatically by the installer:

```bash
zedproxy-update
```

### What the update script does

1. **Verifies** the project exists at `/var/www/zedproxy` before touching anything
2. **Creates a backup** in `/var/backups/zedproxy/updates/YYYYMMDD_HHMMSS/`:
   - Current commit hash
   - `.env` copy (permissions 600)
   - PostgreSQL full dump (`pg_dump -Fc`) — update is aborted if this fails
3. **Enables maintenance mode** (`php artisan down --render="errors::503"`)
4. **Pulls latest code** (`git fetch + reset --hard origin/main + clean -fd`)
5. `composer install --no-interaction --prefer-dist --optimize-autoloader`
6. `npm ci` (falls back to `npm install`), then `npm run build`
7. `php artisan migrate --force`
8. Seeds missing defaults: `SiteTextSeeder`, `FeatureSeeder`, `LocationSeeder`, `PlanSeeder`, `PaymentMethodSeeder` — all use `firstOrCreate` (never overwrites admin-edited values)
9. `php artisan storage:link`
10. `php artisan optimize:clear` + `config:cache` + `route:cache` + `view:cache`
11. **Disables maintenance mode** (`php artisan up`) — also runs on error
12. Restarts PHP-FPM, Supervisor workers, reloads Nginx
13. **Health check** (HTTP + HTTPS if SSL is active)
14. Prunes update backups older than 30 most recent

### What is preserved through updates

| What | How |
|------|-----|
| `.env` (secrets, DB credentials, APP_URL) | `git reset --hard` never touches `.env` — it is in `.gitignore` |
| `storage/` (uploads, backups, logs) | Never deleted or reset by git |
| `public/storage` symlink | Re-created with `storage:link` (idempotent) |
| PostgreSQL database | Only migrated forward — never dropped or reset |
| Admin-edited site texts | `SiteTextSeeder` uses `firstOrCreate` — never updates existing values |
| Admin-edited plans | `PlanSeeder` uses `firstOrCreate` by slug — never overwrites |
| Admin-edited features | `FeatureSeeder` uses `firstOrCreate` by slug — never overwrites |
| Admin-edited locations | `LocationSeeder` uses `firstOrCreate` by `country_code` — never overwrites |
| SSL/Nginx config | Not touched by `update.sh` |

### Rollback

If an update goes wrong, the final summary prints the rollback commands:

```bash
# Revert to previous code
cd /var/www/zedproxy
git reset --hard <previous-commit-hash>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize:clear

# Restore DB if migrations ran (replace placeholders with values from backup dir)
PGPASSWORD=<db_password> pg_restore \
    -h 127.0.0.1 -p 5432 \
    -U <db_user> -d <db_name> \
    --clean --if-exists \
    /var/backups/zedproxy/updates/<TIMESTAMP>/<db_name>_<TIMESTAMP>.dump

php artisan up
```

### Update log

```bash
sudo tail -n 120 /var/log/zedproxy-update.log
```

## User dashboard and order system

### User routes

| Route | Name | Description |
|-------|------|-------------|
| `GET /dashboard` | `dashboard.index` | User dashboard — shows wallet balance, pending payments, recent orders |
| `GET /dashboard/orders` | `dashboard.orders` | All user orders |
| `GET /dashboard/orders/{order}` | `dashboard.orders.show` | Single order detail with Pay button |
| `GET /dashboard/orders/{order}/pay` | `dashboard.orders.pay` | Choose payment method and submit payment |
| `POST /dashboard/orders/{order}/pay` | `dashboard.orders.pay.submit` | Submit payment (wallet debit or manual submission) |
| `GET /dashboard/wallet` | `dashboard.wallet` | Wallet balance and transaction ledger |
| `GET /dashboard/wallet/topup` | `dashboard.wallet.topup` | Wallet top-up form (preset amounts + payment method) |
| `POST /dashboard/wallet/topup` | `dashboard.wallet.topup.submit` | Submit top-up → redirect to NOWPayments/CentralPay |
| `GET /dashboard/services` | `dashboard.services` | User services list — status, traffic, expiry |
| `GET /dashboard/services/{service}` | `dashboard.services.show` | Service detail — config link, traffic bar, expiry, related order |
| `GET /dashboard/profile` | `dashboard.profile` | User profile (read-only) |
| `POST /plans/{plan}/buy` | `plans.buy` | Create order for a plan (auth required) |

Legacy `/panel/*` routes redirect to `/dashboard/*` (301 permanent).

### Buy flow

1. User visits `/plans`
2. If not logged in: buy button says "ورود برای خرید" and links to `/login`
3. If logged in: buy button is a POST form → `POST /plans/{plan}/buy`
4. Server validates plan is active, creates an `Order` with a snapshot of plan data at purchase time
5. Redirects to `/dashboard/orders/{order}`
6. Order detail page shows a **Pay** button when the order is unpaid

### Payment flow

**Wallet payment:**
1. User selects "کیف پول" method on the pay page
2. If wallet balance ≥ order price: atomic debit + immediate approval, order becomes `paid`
3. If balance insufficient: error shown, no transaction created

**Manual payment (crypto / Telegram Stars / Rial transfer):**
1. User selects a manual method, sees instructions and account details
2. User enters TXID / transaction reference and optional note
3. Submits form → `PaymentTransaction` created with status `submitted`, order becomes `awaiting_payment`
4. Admin reviews in Filament → approve (marks order paid) or reject (reverts order to pending)

### Order data model

Orders store a **snapshot** of plan data at purchase time — plan name, slug, traffic, duration, and price. Changing the plan in the admin panel does **not** affect existing orders.

**Order statuses:** `pending` → `awaiting_payment` → `paid` → `processing` → `completed` (or `cancelled`/`failed`)

**Payment statuses:** `unpaid`, `pending`, `paid`, `failed`, `refunded`

### Wallet / ledger

Every change to a user's wallet balance creates a `WalletTransaction` record (append-only). Wallet balance is only modified via `WalletService::credit()` / `debit()`, which use `lockForUpdate()` to prevent race conditions.

**Transaction types:** `manual_credit`, `manual_debit`, `order_payment`, `topup`, `refund`, `adjustment`

### Wallet settings (admin-controlled, no .env edit required)

All wallet behaviour is toggled from `/zed-admin/site-texts` (group: `wallet`):

| Key | Default | Description |
|-----|---------|-------------|
| `wallet_enabled` | `0` | Master switch — hides wallet UI when off |
| `wallet_payment_enabled` | `0` | Allow users to pay orders from wallet balance |
| `wallet_topup_enabled` | `0` | Show the top-up form at `/dashboard/wallet/topup` |
| `wallet_topup_nowpayments_enabled` | `0` | Enable NOWPayments top-up method |
| `wallet_topup_centralpay_enabled` | `0` | Enable CentralPay top-up method (off by default while IP whitelist is pending) |
| `wallet_min_topup_amount` | `10000` | Minimum top-up amount in Toman |
| `wallet_max_topup_amount` | `10000000` | Maximum top-up amount in Toman |
| `wallet_currency` | `IRT` | Wallet currency code |
| `wallet_topup_preset_amounts` | `50000,100000,200000,500000` | Comma-separated preset amounts shown on top-up form |
| `wallet_admin_adjustment_requires_note` | `1` | Require a reason when admin manually credits/debits |

### Wallet top-up via NOWPayments

1. Admin enables `wallet_topup_enabled` and `wallet_topup_nowpayments_enabled`
2. User visits `/dashboard/wallet` → clicks "شارژ کیف پول"
3. User selects amount (preset or custom) and payment method → submitted to `/dashboard/wallet/topup`
4. Server creates a `PaymentTransaction` with `payment_purpose=wallet_topup` (no `order_id`) and sends user to the NOWPayments invoice page
5. NOWPayments sends an IPN to `/webhooks/nowpayments` with `payment_status=finished`
6. Server credits the wallet via `WalletService::creditFromPaymentTransaction()` — idempotent, duplicate IPNs cannot double-credit
7. No `UserService` is created for wallet top-ups

### Payment methods (seeded defaults)

| Slug | Type | Description |
|------|------|-------------|
| `wallet` | `wallet` | Internal wallet balance |
| `manual-crypto` | `manual_crypto` | USDT / TRC20 manual transfer |
| `manual-stars` | `manual_stars` | Telegram Stars via bot |

Admin can create additional methods and edit titles, instructions, and account values without code changes.

### Admin order management

| Admin route | Description |
|-------------|-------------|
| `/zed-admin/orders` | List all orders, filter by status/payment_status |
| `/zed-admin/orders` | Quick actions: mark processing, mark completed, cancel with note, **create service** (paid orders without a service) |
| `/zed-admin/orders/{id}/edit` | Edit order status, payment status, timestamps, admin notes |
| `/zed-admin/payment-transactions` | Approve or reject submitted payments with admin note |
| `/zed-admin/wallet-transactions` | Read-only ledger of all wallet debits and credits |
| `/zed-admin/users` | Credit or debit any user's wallet with a reason |

### What is preserved through updates

Orders, wallet transactions, payment transactions, and payment methods are never deleted by `update.sh` or seeders. The `--force` migrate only runs forward migrations. `PaymentMethodSeeder` uses `firstOrCreate` — admin-edited method titles, instructions, and account values are never overwritten.

## Service lifecycle

### Service statuses

| Status | Description |
|--------|-------------|
| `pending_provision` | Service created but not yet provisioned on a VPN panel |
| `active` | Provisioned and in use |
| `disabled` | Temporarily suspended by admin |
| `expired` | Past the `expires_at` date |
| `cancelled` | Cancelled before activation |
| `failed` | Provisioning failed |

### Provision statuses

| Status | Description |
|--------|-------------|
| `pending` | Waiting for provisioning to start |
| `manual_required` | Requires admin action (or Marzban panel not configured) |
| `provisioned` | Successfully linked to a VPN panel |
| `failed` | Provisioning error |
| `skipped` | Skipped (no API integration active) |

### Service creation flow

1. User pays an order (wallet or approved manual payment)
2. `PaymentService` calls `ServiceProvisioner::createFromOrder()` — idempotent, safe to call twice
3. A `UserService` record is created with `status=pending_provision`, `provision_status=manual_required`
4. A `VpnServiceProvisionLog` entry is written: `action=create_placeholder_service`, `status=skipped`
5. Admin activates the service manually in the admin panel → dates computed, status set to `active`

### Admin service management

| Admin route | Description |
|-------------|-------------|
| `/zed-admin/user-services` | List all services with status, traffic, expiry |
| `/zed-admin/user-services/{id}/edit` | Edit service: status, config link, subscription link, VPN panel assignment, admin notes |
| Quick action: Activate | Sets status to `active`, computes `starts_at` / `expires_at`, logs `manual_activate` |
| Quick action: Disable | Sets status to `disabled`, logs `manual_disable` |
| Quick action: Cancel | Sets status to `cancelled`, logs `manual_cancel` |

### Artisan commands

```bash
# Mark expired services (run daily via cron or Supervisor)
php artisan services:expire
```

Add to cron for daily expiry checks:

```bash
echo "0 1 * * * www-data php /var/www/zedproxy/artisan services:expire >> /var/log/zedproxy-expire.log 2>&1" \
    | sudo tee /etc/cron.d/zedproxy-expire
```

### VPN panel placeholders

`VpnPanel` and `VpnInbound` models and migrations are in place for future Marzban / 3x-ui integration. Admin resources exist at:

- `/zed-admin/vpn-panels` — add VPN panel connection details (name, type, URL, credentials)
- `/zed-admin/vpn-inbounds` — add inbounds linked to a panel (protocol, port, network, security)

> **No real VPN API connection is active.** Panels and inbounds can be configured in the admin but ZedProxy does not yet call any external VPN API. Automatic service provisioning via Marzban or 3x-ui is planned for a future phase.

### Service data preserved through updates

User services, provision logs, VPN panels, and VPN inbounds are **never deleted** by `update.sh` or seeders. Admin-edited config links, admin notes, and activation dates survive all updates.

## What's next

**Completed:**
- Plans, Features, Locations admin CRUD — fully DB-backed
- Site texts system with `site_setting()` helper
- Public plans page at `/plans` — active plans with buy buttons
- Update-safe seeders (`firstOrCreate` — never overwrites admin edits)
- User login/register with username auth
- User dashboard at `/dashboard` — active services, pending services, wallet balance, recent orders
- Order system with plan snapshot storage
- Payment methods model with seeded defaults (wallet, crypto, stars)
- Wallet system — atomic balance management via `WalletService` with pessimistic locking
- Wallet payment — immediate debit + order approval if balance sufficient
- Manual payment submission — user submits TXID/reference, admin approves/rejects in Filament
- Admin approve/reject with admin note — `PaymentService` handles idempotent approval
- Admin wallet management — credit/debit any user's wallet from UserResource
- Wallet ledger — `WalletTransaction` append-only ledger, visible to user and admin
- User wallet page at `/dashboard/wallet`
- Admin payment method management at `/zed-admin/payment-methods`
- **UserService model** — full service lifecycle (pending_provision → active → disabled/expired/cancelled)
- **Service auto-creation** — `ServiceProvisioner` called automatically on payment approval (idempotent)
- **User services pages** — `/dashboard/services` list and `/dashboard/services/{service}` detail
- **Admin UserServiceResource** — activate, disable, cancel actions; full CRUD; provision logs
- **VpnPanel** — model, migration, Filament resource with Test Connection, Refresh Token, Mark Default, Open API Docs actions
- **VpnInbound** — model, migration, Filament resource linked to panels
- **VpnServiceProvisionLog** — append-only log of all lifecycle events
- **`services:expire` command** — bulk-marks active services past their expiry date
- **Dashboard updated** — shows active service count, pending service count, services quick link
- **Order detail updated** — shows link to related service when it exists
- **Marzban API client** — `MarzbanClient` with login/testConnection/createUser/getUser/updateUser/deleteUser/resetTraffic/revokeSubscription; token cached in Redis; retry-on-401; never logs tokens or passwords
- **`ProvisionMarzbanServiceJob`** — queued job; idempotent (update if user exists, create if not); 409-conflict handled (get+update); saves subscription_url and config_link from Marzban response
- **Automatic provisioning** — `ServiceProvisioner::createFromOrder()` dispatches `ProvisionMarzbanServiceJob` when a default active Marzban panel is configured
- **Full admin action set** in UserServiceResource — Recreate, Sync, Reset Traffic, Revoke Subscription (تغییر لینک اشتراک), Disable, Enable, Delete Remote, Clear Local Links, View Subscription QR
- **Server-side QR codes** — `simplesoftwareio/simple-qrcode` generates inline SVG QR for subscription and config links; no CDN or JavaScript dependency; QR updates automatically after revoke subscription
- **Subscription + config link display** — user sees subscription URL with copy button and QR code, plus config link QR at `/dashboard/services/{service}`; admin sees QR in modal via **مشاهده بارکد لینک اشتراک**
- **User self-service Marzban actions** — `UserServiceActionController` with 5 POST routes (sync, revoke-subscription, reset-traffic, disable, enable); ownership enforced; throttled; API failures return flash errors, never crash
- **Admin-controlled feature flags** — `ServiceSettingsSeeder` seeds 6 `SiteText` settings (group: `services`); sync+revoke enabled by default; reset/disable/enable disabled by default; admin edits via `/zed-admin/site-texts`
- **Per-service revoke rate limit** — revoke subscription limited to once per 10 minutes per service (configurable); excess attempts get Persian error message
- **Provision logs for user actions** — every user action creates a `VpnServiceProvisionLog` with action prefix `user_marzban_*`; no tokens or credentials logged
- **Per-VPN-panel user self-service toggles** — 10 boolean columns on `vpn_panels` control which actions users can perform per panel (sync, revoke, reset-traffic, disable, enable, view QR, copy links); defaults match previous global settings
- **Auto-sync on service detail view** — `/dashboard/services/{service}` syncs from Marzban if never synced or last sync >30s ago; graceful failure with warning banner
- **NOWPayments crypto payment gateway** — hosted invoice mode (default, customer chooses currency on NOWPayments) and direct mode; IPN webhook at `POST /webhooks/nowpayments` with HMAC-SHA512 signature verification; IPN matching by invoice_id → provider_reference; manual status check; auto-provisioning on `finished`; currency conversion IRT→USD via admin-configured exchange rate; `api_key` and `ipn_secret` stored encrypted; QR code on payment detail page; 48 automated tests
- **CentralPay rial payment gateway** — server-to-server verify (never trusts GET alone); amount in Toman; orderId = payment_transactions.id (avoids duplicate_orderId on retries); idempotent (reuses active tx gateway_url); amount mismatch and userId mismatch detection; card number masked before storage (first6 + ****** + last4); api_key stored encrypted in DB and managed from `/zed-admin/payment-methods` (no `.env` keys required after first deploy); all config (base_url, type, amount_unit, callback_path) editable from admin panel; one-time env→DB migration on first update; admin verify action in Filament; 44 automated tests

**Next:**
1. Renew / extra traffic — extend Marzban user expiry or add data via order
2. 3x-ui / Sanaei 3x-ui integration — same flow, different panel type
3. Ticket system — support ticket model and panel
4. Telegram bot — admin reports and notifications
5. Email — order confirmations, expiry reminders
6. Docker deployment — containerized installation

## NOWPayments crypto gateway

ZedProxy integrates with [NOWPayments](https://nowpayments.io) to accept cryptocurrency payments automatically. The gateway handles invoice creation, IPN webhook verification, and auto-provisioning on confirmed payment.

### Payment modes

There are two modes, configurable per payment method:

| Mode | How it works |
|------|-------------|
| **Invoice (default)** | ZedProxy creates a hosted invoice via `POST /v1/invoice`. Customer is redirected to the NOWPayments checkout page and **chooses the crypto currency there**. ZedProxy does not need to know the currency in advance. |
| **Direct** | ZedProxy creates a payment via `POST /v1/payment` with a specific `pay_currency`. Customer pays to the wallet address displayed directly on ZedProxy. |

**Invoice mode is recommended.** It requires no currency config on ZedProxy and lets customers choose from all currencies NOWPayments supports.

### How it works (invoice mode)

1. Admin enables the NOWPayments payment method, sets mode to **Invoice** (default)
2. User selects "پرداخت کریپتو (NOWPayments)" at checkout and clicks "تایید و پرداخت"
3. ZedProxy calls `POST /v1/invoice` — **no crypto currency needed at this point**
4. User is redirected to the hosted NOWPayments checkout page (`invoice_url`)
5. User chooses currency and network on NOWPayments, completes the payment
6. NOWPayments sends an IPN webhook when the payment status changes
7. The IPN delivers both `invoice_id` and `payment_id` — ZedProxy matches by `invoice_id` and stores `payment_id` in `external_id`
8. When status is `finished`, ZedProxy marks the order paid and provisions the VPN service automatically
9. User can also click "بررسی وضعیت پرداخت" to manually poll — only works after the customer has started paying (i.e., after the first IPN with a `payment_id`)

### How it works (direct mode)

1. Admin sets mode to **Direct**, sets `default_pay_currency` (e.g. `usdttrc20`)
2. User selects the payment method; ZedProxy calls `POST /v1/payment` with the configured currency
3. User sees the wallet address, exact amount, QR code, and expiry on `/dashboard/orders/{order}/nowpayments`
4. User pays; IPN webhook updates status; `finished` provisions the service

### Setup (admin)

1. Go to `/zed-admin/payment-methods` → **New**
2. Set **Type** to `NOWPayments (کریپتو)`
3. Fill in the NOWPayments configuration section:

| Field | Description |
|-------|-------------|
| **API Key** | From [NOWPayments dashboard](https://nowpayments.io) → API Keys. Stored encrypted. |
| **IPN Secret** | From NOWPayments dashboard → API Keys → IPN Secret. Stored encrypted. |
| **Payment mode** | `Invoice` (recommended) — customer chooses currency on NOWPayments; `Direct` — currency fixed in advance |
| **Sandbox mode** | Enable for testing — uses `api-sandbox.nowpayments.io`. Disable for production. |
| **Site currency** | `IRT` (Toman) or `IRR` (Rial) — the currency your order prices are in |
| **Exchange rate (Toman/USD)** | Manual exchange rate. Example: `75000` means 75,000 Toman = 1 USD |
| **Price currency** | `usd` (default) — the currency NOWPayments converts to when creating the invoice |
| **Default pay currency** | *(Direct mode only)* Crypto to pay with, e.g. `usdttrc20`, `btc`, `eth` |
| **Allowed pay currencies** | *(Direct mode only)* Comma-separated list shown to user, e.g. `btc,eth,usdttrc20,ltc` |
| **IPN Callback URL** | Leave empty — ZedProxy auto-fills with `/webhooks/nowpayments` |
| **Success URL** | Optional redirect after successful payment (defaults to order detail page) |
| **Cancel URL** | Optional redirect on cancelled payment (defaults to payment selection page) |
| **Base URL** | Leave empty for auto-detection by sandbox toggle |

4. Set **Active** to enabled
5. Save

### Webhook URL

Tell NOWPayments your IPN callback URL:

```
https://yourdomain.com/webhooks/nowpayments
```

This is filled automatically when you submit a payment if `ipn_callback_url` is empty in the config.

### Currency conversion

ZedProxy prices are in Toman (IRT) but NOWPayments expects USD (or another supported currency).

The admin must set `exchange_rate_usd` — the number of Toman per 1 USD. Example: if the rate is 75,000 Toman/USD:

```
order.final_price_toman ÷ 75,000 = USD price sent to NOWPayments
```

Update the exchange rate regularly from the admin panel to keep prices accurate.

### IPN matching (invoice mode)

In invoice mode, NOWPayments sends an `invoice_id` alongside the `payment_id` in each IPN. ZedProxy matches transactions in this priority:

1. `invoice_id` → `provider_reference` (the invoice id stored when the invoice was created)
2. `payment_id` → `provider_reference` or `external_id` (for direct mode or subsequent IPNs)
3. `order_id` → `order_id` column (last resort)

The `payment_id` is stored in `external_id` the first time it appears in an IPN. This allows "بررسی وضعیت پرداخت" to call `GET /v1/payment/{payment_id}` for live status.

### Supported NOWPayments statuses

| NOWPayments status | ZedProxy action |
|--------------------|-----------------|
| `waiting` | Transaction status = `waiting`, order stays pending |
| `confirming` | Transaction status = `confirming`, order stays pending |
| `confirmed` | Transaction status = `confirming`, order stays pending |
| `sending` | Transaction status = `confirming`, order stays pending |
| `partially_paid` | Transaction status = `partially_paid`, order stays pending |
| `finished` | **Order marked paid, VPN service provisioned automatically** |
| `failed` | Transaction status = `failed` |
| `refunded` | Transaction status = `refunded` |
| `expired` | Transaction status = `expired` |

**Only `finished` triggers provisioning.** This is intentional — blockchain confirmations take time and partial/in-progress payments must not grant access.

### IPN signature verification

Every IPN request from NOWPayments is verified before processing:

1. Read `x-nowpayments-sig` header
2. Sort all payload keys alphabetically
3. JSON-encode with sorted keys
4. Sign with `HMAC-SHA512` using `ipn_secret`
5. Compare with `hash_equals()` (constant-time, prevents timing attacks)

Requests with missing or invalid signatures are rejected with `401` and logged (without exposing the secret).

### Manual status check

Users and admins can manually check payment status without waiting for an IPN:

- **User**: click "بررسی وضعیت پرداخت" on the payment detail page
- **Admin**: click "بررسی وضعیت NOWPayments" in `/zed-admin/payment-transactions`

Both call `GET /v1/payment/{payment_id}` on the NOWPayments API and update the transaction in real time.

> **Invoice mode note**: The manual status check requires a `payment_id` (stored in `external_id`). This is only available after the customer has chosen a currency and started paying on the NOWPayments page. Before that, the check returns "پرداخت هنوز توسط کاربر انتخاب/شروع نشده است" and the user should complete payment on NOWPayments first.

### Security

- `api_key` and `ipn_secret` are stored with Laravel's `encrypted` cast — encrypted at rest using `APP_KEY`
- Neither field appears in admin table views or JSON responses
- Credentials are never logged — not in API calls, IPN handling, or error messages
- IPN is verified before any database update
- Order ownership is verified on every user-facing action
- Duplicate IPN calls are idempotent — provisioning runs at most once per order

### Sandbox / production

| Mode | Base URL |
|------|----------|
| Sandbox (testing) | `https://api-sandbox.nowpayments.io/v1` |
| Production | `https://api.nowpayments.io/v1` |

Enable sandbox in the admin config field. Disable it when going live.

### Troubleshooting

**"نرخ تبدیل دلار تنظیم نشده است"**
→ Set `exchange_rate_usd` in the payment method config to a positive value.

**"ساخت فاکتور NOWPayments انجام نشد"**
→ The NOWPayments API returned a response without an `invoice_url`. Check your API key, ensure the method is not in Direct mode when you expect a hosted invoice, and verify the NOWPayments account is active.

**"مبلغ سفارش برای پرداخت با NOWPayments کمتر از حداقل مجاز"**
→ The order total converted to USD is below NOWPayments' minimum allowed amount. Increase the order price or switch to a crypto with a lower minimum amount.

**"پرداخت هنوز توسط کاربر انتخاب/شروع نشده است"**
→ In invoice mode, the manual status check only works after the customer has chosen a currency on the NOWPayments page. Tell the customer to complete the payment first, then check status.

**IPN not received**
→ Ensure the webhook URL `https://yourdomain.com/webhooks/nowpayments` is reachable from the internet. Check that your firewall or Cloudflare does not block `POST` requests to that path.

**Payment shows "waiting" indefinitely**
→ Click "بررسی وضعیت پرداخت" to manually poll. Or check the NOWPayments dashboard for the payment status. If the payment expired, the user must start a new payment.

**Sandbox payments not completing**
→ Use the NOWPayments sandbox dashboard to simulate status changes, or manually change the transaction status in the admin panel.

**Admin transactions page**
→ Go to `/zed-admin/payment-transactions`, find the transaction (provider = nowpayments), and use:
- "بررسی وضعیت NOWPayments" — polls live status from API
- "پاسخ درگاه" — shows the raw (sanitized) API response JSON

## CentralPay rial payment gateway

ZedProxy integrates with [CentralPay](https://centralapi.org) to accept rial (Toman) payments from Iranian users.

### How it works

1. User selects "پرداخت ریالی" at checkout and clicks "تایید و پرداخت"
2. ZedProxy calls `POST .../getLink.php` to create a payment link
3. User is redirected to the CentralPay payment page
4. After payment, CentralPay redirects back to ZedProxy's callback URL
5. ZedProxy immediately calls `POST .../verify.php` server-to-server — the GET callback alone is never trusted
6. On successful verify, the order is marked paid and the VPN service is provisioned automatically

### Setup (admin)

All CentralPay configuration is managed from the admin panel — no `.env` keys are required after installation.

1. Go to `/zed-admin/payment-methods`
2. Find "پرداخت ریالی" (slug: `centralpay`) and click **Edit**
3. Fill in the **تنظیمات CentralPay** section:

| Field | Description |
|-------|-------------|
| **API Key** | From your CentralPay merchant account. Stored encrypted — never exposed in UI or logs. |
| **آدرس پایه CentralPay** | Default: `https://centralapi.org/webservice/basic` — change only if instructed by CentralPay. |
| **واحد مبلغ** | `TOMAN` (default) — ZedProxy sends amounts in Toman. |
| **نوع تراکنش** | `deposit` (default) — as required by CentralPay documentation. |
| **مسیر بازگشت پرداخت** | Default: `/payments/centralpay/callback` — the path CentralPay redirects to after payment. |

4. Toggle **Active** on
5. Save

The **آدرس بازگشت پرداخت** placeholder shows the full callback URL you need to register in your CentralPay merchant panel (e.g. `https://yourdomain.com/payments/centralpay/callback`).

The CentralPay payment method is seeded as **inactive by default**. It will not appear in checkout until you set an API key and enable it.

> **Leaving the API key field empty on edit** preserves the existing encrypted key — you only need to re-enter it when you want to change it.

#### Migrating from `.env`-based config

If you previously used `CENTRALPAY_API_KEY`, `CENTRALPAY_BASE_URL`, etc. in `.env`, those values are **automatically imported** the first time the seeder runs after this update (i.e., when `update.sh` runs). The import is one-time and never overwrites values you have already set in the admin panel. After the import, the `.env` keys are no longer read and can be removed.

### Callback URL

Register this URL in your CentralPay merchant panel:

```
https://yourdomain.com/payments/centralpay/callback
```

The exact URL is shown in the admin edit form under **آدرس بازگشت پرداخت (جهت ثبت در CentralPay)** and will reflect any custom `callback_path` you configure.

ZedProxy uses a GET callback for redirect only — all verification is done server-to-server via POST to `/verify.php`.

### Amount

ZedProxy stores prices in Toman. The amount is sent to CentralPay as an integer in Toman with no conversion. Example: an order with `final_price_toman = 200000` sends `amount = 200000` to CentralPay.

### orderId and idempotency

ZedProxy uses `payment_transactions.id` (not `orders.id`) as the CentralPay `orderId`. This avoids the `duplicate_orderId` error from CentralPay when a user retries payment for the same order — each retry creates a new `PaymentTransaction` record with a new `id`.

If a CentralPay payment is already pending (status `pending` or `waiting`, `gateway_url` set), the user is redirected to the existing payment URL without calling `getLink.php` again. This prevents duplicate payment sessions.

### Verify behavior

| Condition | Result |
|-----------|--------|
| Verify API call fails (HTTP error) | Error shown to user; tx stays pending |
| `verify.data.status` ≠ success | Error shown; tx marked failed; `failure_reason` saved |
| Amount in verify ≠ `gateway_amount` | `gateway_status = amount_mismatch`; **NOT marked paid**; user sees error |
| userId in verify ≠ `order.user_id` | `gateway_status = user_mismatch`; **NOT marked paid** |
| Already paid (idempotency guard) | Redirected to order page; verify NOT called again |
| All checks pass | Order marked paid; VPN service provisioned; `gateway_status = verified` |

### Card number masking

CentralPay returns the user's card number in the verify response. ZedProxy masks it before storage:

```
1111222233334444  →  111122******4444
```

The masked number is stored in `response_payload` JSON. The raw card number is never saved to the database or logged.

### Security

- `api_key` is stored in the `payment_methods` table with Laravel's `encrypted` cast — encrypted at rest using `APP_KEY`
- The api_key is hidden from model JSON serialization (`$hidden`) and never shown in admin table columns
- The api_key is stripped from all stored payloads (`request_payload`, `response_payload`) before saving
- The api_key is never logged — not in API calls, errors, or debug output
- Card numbers are masked before storage (first 6 + `******` + last 4)
- The GET callback is never trusted without a server-to-server POST verify
- Duplicate provisioning is prevented — verify is skipped for already-paid orders

### Admin actions

Go to `/zed-admin/payment-transactions`, filter by provider `centralpay`:

| Action | Description |
|--------|-------------|
| **بررسی وضعیت CentralPay** | Calls `/verify.php` server-to-server and processes the result |

The admin verify action is visible for CentralPay transactions that are not yet in a terminal state (`verified`, `amount_mismatch`, `user_mismatch`) and whose order is not already paid.

### Troubleshooting

**"درگاه پرداخت ریالی در حال حاضر پیکربندی نشده است"**
→ The API key is missing. Go to `/zed-admin/payment-methods` → edit "پرداخت ریالی" → fill in the **کلید API CentralPay** field and save.

**"درگاه CentralPay فعال نیست"** / method not showing at checkout
→ Toggle **Active** on in the payment method edit form and save. The method is inactive by default.

**"خطا در ایجاد لینک پرداخت CentralPay"** / "اتصال به درگاه پرداخت ناموفق بود"
→ Check that the **کلید API CentralPay** and **آدرس پایه CentralPay** are set correctly in the admin panel. Inspect `storage/logs/laravel.log` for the sanitized error response.

**"مبلغ تاییدشده با مبلغ سفارش مطابقت ندارد"**
→ The verified amount from CentralPay does not match the stored gateway amount. The transaction is marked `amount_mismatch` and the order is NOT paid. Contact the user and check the CentralPay merchant dashboard.

**"خطا در تایید پرداخت CentralPay"** / **"پرداخت CentralPay انجام نشد"**
→ CentralPay returned a non-success status. Check `failure_reason` in the transaction record for details.

**Payment shows pending but user was charged**
→ Use the "بررسی وضعیت CentralPay" action in `/zed-admin/payment-transactions` to manually trigger server-side verify.

**`duplicate_orderId` error from CentralPay**
→ This should not occur — ZedProxy uses `payment_transactions.id` as orderId, which is unique per payment attempt. If it does occur, check for stale pending transactions and mark them failed before retrying.

## VPN Panels: Marzban & Sanaei / 3X-UI

ZedProxy supports two VPN panel provider types side by side:

- **Marzban** (`marzban`)
- **سنایی / 3X-UI** (`sanaei_3xui`)

Both are configured in `/zed-admin` → VPN Panels. Adding a 3X-UI panel never
affects existing Marzban panels or services.

### 3X-UI / Sanaei — authentication (official API only)

ZedProxy talks to 3X-UI **exclusively through its official API**. It never uses
browser automation, never scrapes panel HTML, never simulates UI clicks, and
never performs a manual web login.

- **API Token (recommended):** set `auth_method = API Token` and paste the panel
  API token. Requests are sent with `Authorization: Bearer {token}`.
- **API Login (fallback):** set `auth_method = ورود از طریق API` and provide the
  panel username/password. ZedProxy calls the official `POST {panel}/login`
  endpoint and uses the returned session cookie for API requests (auto re-login
  once on 401). Use this only if an API token is unavailable.

Credentials (`api_token`, `password`) are stored **encrypted** and are never
shown in admin tables, never returned to users, and never logged.

### 3X-UI fields

- **آدرس اصلی پنل (base_url):** e.g. `https://example.com:2053`
- **مسیر پنل (panel_path):** the panel's secret path, e.g. `/M.hosein1384`.
  API URLs are built as `base_url + panel_path + /panel/api/...`.
- **بررسی SSL (verify_ssl):** disable for panels using a self-signed/IP
  certificate.
- **Inbound پیش‌فرض (default_inbound_id):** the inbound new clients are created
  in. Use the **«دریافت لیست Inboundها»** action to discover inbound ids.
- **آدرس/مسیر لینک اشتراک:** used to build the subscription link from the client
  `subId` when the panel doesn't return a direct link.

### Supported actions (3X-UI)

Create service, sync (traffic/expiry/status), subscription link + QR, renewal,
extra traffic, extra time, enable/disable, reset traffic, and revoke/regenerate
subscription — each implemented against the documented client endpoints and
gated safely (an unsupported capability shows «این قابلیت برای این نوع پنل
پشتیبانی نمی‌شود.» rather than crashing).

> Sensitive data (tokens, passwords, cookies, sessions, subscription links) is
> never written to logs.
