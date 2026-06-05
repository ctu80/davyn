# Davyn

**Self-hosted CalDAV & CardDAV suite** — your own calendars and contacts, synced
to your phone and desktop, with a modern web UI. For home and small-team use.

> ### ⚠️ Alpha — `0.5.0-alpha`
> Davyn is under active development. It is usable and tested, but expect rough
> edges, possible breaking changes between releases, and incomplete DAV coverage
> (see [Limitations](#limitations)). Don't use it as the *only* copy of important
> data — [back up `./data`](docs/backup-restore.md).

## What is Davyn?

Davyn is a single Docker Compose stack that gives you a private calendar and
contacts server you fully control. Under the hood the CalDAV/CardDAV layer is built
on **[SabreDAV](https://sabre.io/)**, and public-holiday calendars are generated with
**[Yasumi](https://github.com/azuyalabs/yasumi)**.

- **CalDAV & CardDAV** server ([SabreDAV](https://sabre.io/)) — works with DAVx⁵,
  Thunderbird, Apple Calendar/Contacts and other standard clients.
- **Web UI** — a modern React app for calendars, contacts, search, import/export,
  sharing and administration.
- **Sharing** — share calendars and address books with other users
  (`read_only` / `read_write`), enforced both in the UI and over DAV.
- **Generated calendars** — automatic birthday calendars from your contacts and
  subscribable public-holiday calendars (holidays via [Yasumi](https://github.com/azuyalabs/yasumi)).
- **Admin tooling** — first-run setup wizard, user management, scheduled backups,
  import/export, maintenance mode, and an optional internal HTTPS / TLS manager.
- **Single SQLite file** — all data in `./data/davyn.sqlite`; trivial to back up.

## Requirements

- A host with **Docker** + the **Docker Compose** plugin.

**For a normal Docker installation you do not need Node.js, PHP, Composer, or any
local build toolchain.** Davyn ships as a single pre-built image that already
contains the PHP runtime, the SabreDAV backend, the built React UI and Caddy.
(Those tools are only needed to [build from source](#build-from-source).)

## Quickstart (Docker)

You only need two files: `docker-compose.yml` and `.env`. Grab them from this
repository (or `git clone` it — either works), then:

```bash
# 1. Configure — copy the template and set a secret
cp .env.example .env
#   APP_SECRET is required in production: openssl rand -hex 32
#   Set BASE_URL to your public URL if you have one.

# 2. Pull the pre-built image and start
docker compose pull
docker compose up -d
```

That's it — no build step. Davyn is now at **http://localhost:8080**. The database
is migrated automatically on start; open **/setup** to create the first admin.

> The image is published as `ghcr.io/ctu80/davyn:0.5.0-alpha` (also `:latest-alpha`).
> It must be a **public** package for `docker compose pull` to work without a login.
> Prefer to build it yourself? Use the [build-from-source](#build-from-source) path,
> which produces the same image locally.

Full instructions, updates and the security checklist: **[docs/deployment.md](docs/deployment.md)**.

### Configuration (`.env`)

Copy `.env.example` to `.env` and edit it. Every key is documented inline; the
most important ones:

| Variable | Purpose |
|---|---|
| `APP_ENV` | `production` for real deployments, `dev` for local plain-HTTP testing |
| `APP_SECRET` | **Required in production** — random string ≥ 32 chars (`openssl rand -hex 32`) |
| `BASE_URL` | Public URL (e.g. `https://dav.example.com`) used for DAV/links/QR codes |
| `HOST_HTTP_PORT` / `HOST_HTTPS_PORT` | Host ports to publish (default `8080` / `8443`) |

The image also boots with safe built-in defaults if no `.env` is present. Data and
the SQLite database live in the `davyn_data` / `davyn_config` Docker volumes.

### First-run setup

A fresh Davyn has **no admin yet**. The database migrates itself on first start,
so just open the setup wizard:

```
http://localhost:8080/setup
```

Enter a username, optional display name and a password (min. 8 chars). The wizard
creates the **admin** account, signs you in, and then locks itself — `/setup` is
only reachable while no admin exists. Until then, `/login` and the app redirect to
`/setup` automatically.

Prefer the shell? (Refuses to run once an admin exists.)

```bash
docker compose exec davyn php bin/create-admin.php \
  --username admin --display-name "Admin" --password "ChangeMe123!"
```

Add further users in the web UI (**Admin → Users**) or via
`bin/create-user.php` — see [docs/CLI.md](docs/CLI.md).

## Going to production

For anything internet-facing, run Davyn behind a reverse proxy that terminates
public TLS (Let's Encrypt), keep `APP_ENV=production`, and set `BASE_URL`.
Davyn does not manage public certificates itself.

→ **[docs/reverse-proxy.md](docs/reverse-proxy.md)** (covers NPM Plus, Traefik,
nginx, … and the optional internal HTTPS on `:8443`).

## Connecting clients (DAVx⁵, Thunderbird, Apple …)

Create an **app password** (Account → App Passwords) and point your client at the
DAV root:

```
https://<your-host>/dav/
```

| What | URL |
|---|---|
| DAV root (discovery) | `…/dav/` |
| Calendar | `…/dav/calendars/<user>/<collection>/` |
| Address book | `…/dav/addressbooks/<user>/<collection>/` |

→ Per-client steps and sharing details: **[docs/dav-clients.md](docs/dav-clients.md)**.

## Data, backups & updates

- **Persistence:** all state lives in the `davyn_data` volume (SQLite DB, backups,
  import/export) and `davyn_config` (TLS certs). Back these up to keep your content.
- **Backups:** schedule them in **Admin → Backups** or via the CLI →
  **[docs/backup-restore.md](docs/backup-restore.md)**.
- **Updates:** bump the image tag (or `docker compose pull`) then
  `docker compose up -d`. Migrations apply automatically on start.
  See [docs/deployment.md](docs/deployment.md#updating).

## Build from source

Only needed for development or to build your own image — a normal install uses the
pre-built image above and needs none of this. The build compiles the React UI and
installs PHP dependencies **inside** the image, so the host still only needs Docker:

```bash
git clone https://github.com/ctu80/davyn.git
cd davyn
cp .env.example .env            # set APP_SECRET (or APP_ENV=dev for local HTTP)
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build
```

For a live-reload development setup (bind-mounted PHP source, host-visible data,
running the Vite dev server, tests), see **[docs/development.md](docs/development.md)**.

## Limitations

- **Database:** SQLite only. MariaDB/MySQL is **not** supported in this release
  (it is on the roadmap; `DB_DRIVER` other than `sqlite` is rejected).
- **DAV:** basic event/task/contact PUT/GET/DELETE/PROPFIND and sync work.
  Recurring-event edge cases and scheduling/free-busy are not fully evaluated;
  `MKCALENDAR` and collection-level DELETE over DAV return `501` (manage
  collections in the web UI).
- **Alpha:** breaking changes between releases are possible.

## Security

- No default accounts or passwords — the first admin is created via `/setup`.
- `APP_SECRET` is enforced in production; the app refuses to start without it.
- Session cookies are `HttpOnly` + `SameSite` and become `Secure` automatically in
  production. CSRF tokens protect all state-changing requests; sensitive admin
  actions require step-up re-authentication.
- Hardened headers (CSP, `X-Frame-Options: DENY`, `nosniff`, HSTS), `expose_php`
  off, no directory listing, PHP errors logged (never rendered).
- DAV clients should use **app passwords**, not the account password.

See the full [security checklist](docs/deployment.md#security-checklist).

## Documentation

| Guide | |
|---|---|
| [Deployment](docs/deployment.md) | Install, update, persistence, troubleshooting |
| [Reverse proxy & HTTPS](docs/reverse-proxy.md) | Public TLS, internal HTTPS, Force-HTTPS |
| [DAV clients](docs/dav-clients.md) | DAVx⁵ / Thunderbird / Apple setup, sharing |
| [Backup & restore](docs/backup-restore.md) | Scheduled + manual backups, restore, maintenance |
| [CLI reference](docs/CLI.md) | All `bin/*.php` admin scripts |
| [Development](docs/development.md) | Local dev, frontend, tests, migrations |

## Stack

All of the below run inside the single `davyn` container/image, with **Caddy** and
**PHP-FPM** supervised together by [s6-overlay](https://github.com/just-containers/s6-overlay)
(each is auto-restarted if it crashes):

- **Caddy 2** — web server / router
- **PHP 8.3 FPM** (Debian 13 "Trixie") — application server
- **[SabreDAV](https://sabre.io/) 4.x** — CalDAV/CardDAV framework
- **[Yasumi](https://github.com/azuyalabs/yasumi)** — public-holiday provider
- **SQLite** — database (in the `davyn_data` volume)
- **React 19 + Vite 6 + TypeScript + Tailwind** — web UI (built into the image)

## Development

Developers need **Node.js 20+** (UI build / Vite dev server) and **PHP 8.3 +
Composer** (tests) locally. The full workflow — live-reload, frontend dev server,
unit and smoke tests — is in **[docs/development.md](docs/development.md)**.

```bash
bash scripts/release-check.sh   # version + UI build/typecheck + compose validation (+ smoke if running)
```

## License

Davyn is licensed under the [Apache License 2.0](LICENSE).
