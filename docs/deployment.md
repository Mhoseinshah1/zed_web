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

Each release links its `.env` and `storage` to `shared/`, so the `APP_KEY` and
uploaded files are identical across releases and never rewritten.

> **Nginx root** must resolve through `current`, e.g. `root /var/www/zedproxy/current/public;`.

## Commands

| Command | Purpose |
| --- | --- |
| `zedproxy-update` | Build + activate a new release (atomic, auto-rollback on failure). |
| `zedproxy-rollback [release-id] [--yes]` | Switch `current` back to a previous healthy release. |
| `zedproxy-deploy-status [--json]` | Show active/previous release, result, migrations, health. |

They wrap `scripts/deploy/deploy.sh`, `rollback.sh`, and `deploy-status.sh`.

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
   restart workers → readiness checks → exit maintenance only on success.
6. **Health** — `/health`, `/health/live`, homepage, `/login`, plus infra
   (`zedproxy:health`) and scheduler (`schedule:list`). A failed required check
   fails the deployment; the success flag is never set after a failed check.

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

## Compatibility migration (existing single-directory install)

The first `zedproxy-update` on a legacy install runs a one-time bootstrap
(`dep_ensure_shared`) that moves the existing `.env` and `storage` into `shared/`
without altering their content (encryption key + uploads preserved). Then, once,
repoint the Nginx root to `/var/www/zedproxy/current/public` and reload Nginx.
Supervisor programs continue to run the workers against `current`.

## Configuration (env)

`ZPD_BASE`, `ZPD_LOCK_FILE`, `ZPD_LOG_DIR`, `ZPD_BACKUP_DIR`, `ZPD_KEEP_RELEASES`,
`ZPD_MIN_DISK_MB`, `ZPD_HEALTH_URL`, `ZPD_REPO_URL`, and injectable command names
(`ZPD_COMPOSER`, `ZPD_NPM`, `ZPD_PHP`, `ZPD_PG_DUMP`, `ZPD_SYSTEMCTL`, `ZPD_NGINX`,
`ZPD_SUPERVISORCTL`, `ZPD_CURL`, `ZPD_GIT`) — the last group lets the shell tests
mock every system command.

## Tests

`bash tests/deploy/run-tests.sh` runs 25+ mocked scenarios (successful deploy,
lock contention, missing `.env`, empty `APP_KEY`, failed DB backup, composer/npm/
build failures, migration failure, nginx/php-fpm/queue failures, health failure,
atomic switch, automatic rollback, existing-install migration, upload/`.env`/key
preservation, low disk, retention, manual rollback, interrupted deploy, scheduler
verification, invalid manifest). Nothing real is deployed and no system service
is modified.
