# Davyn CLI Reference

All scripts run inside the Davyn container:
```
docker compose exec davyn php bin/<script>.php [args]
```

## System

| Script | Description |
|---|---|
| `doctor.php` | System health check and diagnostics |
| `migrate.php` | Apply pending database migrations |
| `validate-data.php` | Data integrity checks |
| `maintenance.php status\|enable\|disable` | Maintenance mode management |

## Users

| Script | Description |
|---|---|
| `create-user.php --username --display-name --role [--password]` | Create a user |
| `list-users.php` | List all users |
| `change-password.php --username --password` | Set user password |
| `set-user-active.php --username --active 1\|0` | Activate/deactivate user |

## App Passwords

| Script | Description |
|---|---|
| `create-app-password.php --username --name --password` | Create app password (for DAVx5) |
| `list-app-passwords.php --username` | List app passwords |
| `revoke-app-password.php --username --name` | Revoke an app password |

## API Tokens

| Script | Description |
|---|---|
| `create-api-token.php --username --name [--scopes]` | Create API token. Scopes: `read:status,read:collections` |
| `list-api-tokens.php [--username]` | List API tokens |
| `revoke-api-token.php --id` | Revoke an API token |

## Collections

| Script | Description |
|---|---|
| `create-default-collections.php --username` | Create default calendar and addressbook |
| `list-collections.php --username` | List calendars and addressbooks |
| `list-objects.php --username [--calendar\|--addressbook]` | List objects in a collection |
| `list-trash.php --username` | List soft-deleted objects |
| `list-versions.php --type --collection-id --uri` | Show object version history |

## Import / Export

| Script | Description |
|---|---|
| `import-calendar.php --username --calendar --file` | Import .ics into a calendar |
| `import-addressbook.php --username --addressbook --file` | Import .vcf into an addressbook |
| `export-calendar.php --username --calendar --output` | Export calendar to .ics |
| `export-addressbook.php --username --addressbook --output` | Export addressbook to .vcf |

## Generated Calendars

| Script | Description |
|---|---|
| `regenerate-birthday-calendar.php --username` | Regenerate birthday calendar from contacts |
| `generate-holiday-calendar.php --username [--country DE] [--state BW] [--year YYYY]` | Generate holiday calendar |
| `add-external-calendar.php --username --uri --name --url` | Subscribe to an external ICS URL |
| `refresh-external-calendar.php --username --uri [--file]` | Refresh external ICS subscription |
| `find-duplicate-events.php --username [--calendar]` | Find duplicate events |

## Backup & Restore

| Script | Description |
|---|---|
| `backup.php` | Create a new backup |
| `list-backups.php` | List available backups |
| `prune-backups.php` | Remove old backups (respects retention policy) |
| `restore.php --file --dry-run` | Validate a backup (no changes) |
| `restore.php --file --apply --confirm` | Restore from backup |
