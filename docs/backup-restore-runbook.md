# Backup restore runbook (PostgreSQL)

Restoring the **database** portion of a ZedProxy backup archive into a
disposable scratch database, so a backup can be *proved* restorable without
touching production.

> **What this command does NOT do.** It never creates, drops, renames, or cuts
> over a database. It never writes to the live application database. It never
> restores `public/`, `app/`, `resources/`, uploads, or any other file payload
> from the archive. **Production cutover, filesystem restoration, and shared
> storage replacement are not automated** — they remain deliberate, planned
> operator actions, covered in step 8.

---

## 0. Prerequisites

* `psql` and `tar` on the machine running the command; `openssl` as well if
  the archive is encrypted (`.tar.gz.enc`).
* The application's PostgreSQL role, or any role that can connect to the
  scratch database. **No `CREATEDB` privilege is needed** — you create the
  scratch database yourself in step 2.
* Enough free disk for the decrypted archive plus the extracted `database.sql`.

## 1. Locate or copy a trusted backup

Backups live under the configured backup storage path. Work on a **copy**, so
the original artifact stays untouched:

```bash
cp /var/backups/zedproxy/backup-2026-07-28-030000.tar.gz.enc /tmp/restore-test.tar.gz.enc
```

The command requires an **absolute** path ending in `.tar.gz` or
`.tar.gz.enc`. Both plain and encrypted archives produced by the existing
backup system are supported; archives created before this command existed
restore normally, because no manifest is required.

## 2. Create an empty scratch database

The target **must already exist and be completely empty**. Create it owned by
the application role:

```bash
sudo -u postgres createdb -O zedproxy zedproxy_restore_check
```

Name rules (enforced, not escaped — a non-conforming name is rejected):
lowercase letters, digits and underscores, not starting with a digit, at most
63 characters.

The command refuses: the live application database, `postgres`, `template0`,
`template1`, malformed names, and any database that already contains tables or
views in `public`.

## 3. Supply the archive password without putting it in shell history

The password is **never** a command argument. Provide it through the
environment, with a leading space so most shells skip the line in history
(requires `HISTCONTROL=ignorespace`, the default on many systems):

```bash
 read -rs ZP_BACKUP_RESTORE_PASSWORD
 export ZP_BACKUP_RESTORE_PASSWORD
```

`read -rs` does not echo. Alternatively, run the command on an interactive
terminal and leave the variable unset — you will get a hidden prompt. In a
**non-interactive** context (cron, CI, a script) an encrypted archive with no
environment password fails closed rather than prompting.

Unset it as soon as you are done:

```bash
unset ZP_BACKUP_RESTORE_PASSWORD
```

## 4. Run the restore

```bash
php artisan zedproxy:backup-restore /tmp/restore-test.tar.gz.enc \
  --target-database=zedproxy_restore_check
```

On success you get one line with the archive basename, whether it was
encrypted, the target database, the restored table count, and the migration
row count. On failure you get a single bounded sentence and a non-zero exit
code; the safe reason code and numeric process exit status go to the server
log. Raw `psql`, `tar`, and `openssl` output is never printed or logged.

A SQL error aborts the whole restore inside one transaction, so a failed run
leaves **no partially restored schema** behind.

## 5. Query the restored core tables

```bash
psql -h 127.0.0.1 -U zedproxy -d zedproxy_restore_check -c "
  select
    (select count(*) from migrations)    as migrations,
    (select count(*) from users)         as users,
    (select count(*) from orders)        as orders,
    (select count(*) from site_settings) as settings;
"
```

Compare against what you expect for the backup's timestamp. The command's own
post-restore checks are **structural only** (core tables exist, schema is not
empty, migration history is present) and are explicitly *not* a claim that the
application is healthy.

## 6. Run application-level verification against the scratch target

Point a **throwaway** environment at the scratch database — never the live
`.env` — and exercise real read paths:

```bash
DB_DATABASE=zedproxy_restore_check php artisan migrate:status
DB_DATABASE=zedproxy_restore_check php artisan tinker --execute="
  echo App\Models\User::count(), ' users; ',
       App\Models\Order::latest('id')->value('id'), ' latest order id';
"
```

Check the things that actually matter for a recovery: an admin account is
present and its MFA credential survived, recent orders and payments are
consistent, wallet balances reconcile, and sequences are sane (inserting a new
row must not collide with an existing id).

## 7. Remove the scratch database

```bash
sudo -u postgres dropdb zedproxy_restore_check
unset ZP_BACKUP_RESTORE_PASSWORD
```

Also delete the copied archive and any decrypted artifact you created by hand.
The command itself shreds its own work directory on every success and failure
path.

## 8. Plan a real cutover separately

A verified scratch restore proves the **archive** is good. It is not a
cutover. A production restore is a distinct, maintenance-window operation and
is intentionally outside this command's scope:

1. Announce a maintenance window and put the application into maintenance mode.
2. Stop queue workers and the scheduler, so nothing writes mid-restore.
3. Take a **fresh** backup of the current production database first — the
   restore target is the thing you are about to lose.
4. Restore into a new database, then switch the application's configured
   database to it, rather than overwriting the live one in place.
5. Restore file payloads (`public/`, `app/`, `resources/`, uploads) and shared
   storage **separately and manually**; this command extracts only
   `database.sql` and touches nothing on the filesystem.
6. Re-run application verification (step 6) against the restored database
   before letting traffic back in.
7. Restart workers and the scheduler; leave maintenance mode last.
8. Keep the pre-cutover backup until the restored system has run cleanly.
