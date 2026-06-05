# Backup & restore

Davyn stores everything in a single SQLite database (`./data/davyn.sqlite` on the
host). Backups are consistent point-in-time copies created with `VACUUM INTO`, so
they are safe to take while clients are syncing.

## Automatic backups (recommended)

Configure from **Admin → Backups**:

- **Schedule:** Off / Daily / Weekly / Monthly (shipped **off**; Weekly is the
  recommended choice — one click + Save).
- **Retention:** keep backups for *N* days (or *Keep forever*), with an
  *always keep at least N* floor that is never pruned. Pruning runs automatically
  after each scheduled backup.

Scheduling is **request-triggered** — there is no separate cron/worker. A
throttled, lock-guarded check piggybacks on inbound traffic and runs the due
backup *after* the HTTP response, so it never adds latency. CalDAV/CardDAV clients
syncing every few minutes provide the heartbeat. A completely idle server won't
tick — but an idle server has no new data to capture.

The Dashboard's *Latest backup* tile shows whether automation is on and when the
last backup ran.

## Manual backups (CLI)

```bash
# Create a backup now
docker compose exec davyn php bin/backup.php

# List backups
docker compose exec davyn php bin/list-backups.php

# Prune old backups (uses BACKUP_RETENTION_DAYS / BACKUP_MIN_KEEP from .env)
docker compose exec davyn php bin/prune-backups.php
```

Backups are written to `/var/lib/davyn/backups/` (the `davyn_data` volume). Admins
can also download a backup from **Admin → Backups**.

> For an off-host copy, back up the `davyn_data` Docker volume (e.g. with your
> existing backup tooling, or `docker run --rm -v davyn_data:/d -v "$PWD":/out
> alpine tar czf /out/davyn-data.tgz -C /d .`). That captures the database and all
> backups in one archive.

## Restore

Restoring overwrites the active database. Davyn always validates the backup first
and creates a fresh *pre-restore* backup before swapping it in. Maintenance mode
is enabled automatically around an `--apply` restore (and disabled again, even on
error).

```bash
# 1. Validate a backup without touching the live DB
docker compose exec davyn php bin/restore.php \
  --file /var/lib/davyn/backups/davyn-backup-YYYYmmdd-HHMMSS.sqlite --dry-run

# 2. Apply it (validates, takes a pre-restore backup, then restores)
docker compose exec davyn php bin/restore.php \
  --file /var/lib/davyn/backups/davyn-backup-YYYYmmdd-HHMMSS.sqlite --apply
```

Paths are container paths inside the `davyn_data` volume.

## Maintenance mode

During a manual restore (or any maintenance window) you can pause DAV sync while
keeping admin access:

```bash
docker compose exec davyn php bin/maintenance.php enable --reason "planned restore"
docker compose exec davyn php bin/maintenance.php disable
```

While active, `/dav/` returns `503 + Retry-After` for all clients and non-admin
web access is paused; **admins keep full access** and can toggle it from
**Admin → Status → Maintenance**. The `restore.php --apply` command toggles this
automatically.
