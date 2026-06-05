# Security Policy

> Davyn is **alpha** software (`0.5.0-alpha`). Use it accordingly and keep backups
> of your data.

## Supported versions

| Version | Supported |
|---|---|
| `0.5.0-alpha` | ✅ (current) |
| older | ❌ |

During alpha, only the latest release receives security fixes.

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report privately via GitHub:
**Security → Report a vulnerability** (Private Vulnerability Reporting) on this
repository. Include affected version, a description, reproduction steps and impact.

You'll get an acknowledgement as soon as possible. Once a fix is available and
released, the report can be disclosed.

## Hardening notes for operators

- Run Davyn over **HTTPS** (a reverse proxy terminating TLS, or the bundled internal
  HTTPS). Keep `APP_ENV=production` so session cookies are `Secure`.
- Set a strong **`APP_SECRET`** (≥ 32 chars). The app refuses to start in production
  without one.
- Create the first admin via the **`/setup`** wizard — there are no default accounts
  or passwords.
- Have DAV clients use **app passwords**, not the main account password.

See the [security checklist](docs/deployment.md#security-checklist) for more.
