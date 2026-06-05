# Development

This is the **development** workflow, which differs from a
[production deployment](deployment.md). Production pulls a single pre-built image;
development builds that image (and, optionally, bind-mounts the source for live
edits).

Unlike a normal install, development needs local tooling:

- **Node.js 20+** — to build the React UI and run the Vite dev server.
- **PHP 8.3 + Composer** — to run the PHP unit tests.

## Layout

```
app/
  bin/          # CLI scripts (migrate, create-user, backup, restore, import/export, …)
  public/       # Web root (index.php, health.php, dav.php, login.php, api/, setup.php)
  src/          # PSR-4 Davyn\ application code
  migrations/   # Sequential *.sql migrations (run once each, in a transaction)
  tests/        # PHPUnit unit tests
  frontend/     # React + Vite + TypeScript SPA (built into the image)
docker/
  Dockerfile    # All-in-one image: Node build → Composer → PHP-FPM + Caddy + UI
  s6/           # s6-overlay service tree (init-davyn oneshot, php-fpm + caddy longruns)
  scripts/      # davyn-init.sh: dir prep + migrations + conditional-HTTPS stub gen
  caddy/        # Caddyfile
  php/          # php.ini hardening overrides
docs/           # Documentation
```

## Compose files

| File | Purpose |
|---|---|
| `docker-compose.yml` | Production: pulls the pre-built image, named volumes |
| `docker-compose.build.yml` | Build the image locally instead of pulling |
| `docker-compose.dev.yml` | Development: build + bind-mount source + host-visible `./data` + dev mode |

## Running locally (live reload)

```bash
cp .env.example .env          # APP_ENV is forced to dev by the dev override
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
docker compose exec davyn php bin/create-admin.php \
  --username admin --display-name Admin --password "ChangeMe123!"
```

Open http://localhost:8080/ — you'll be routed to `/setup`, `/login` or `/app/`.
The dev override bind-mounts `app/public`, `app/src`, `app/migrations` and
`app/bin`, so PHP changes are live without a rebuild. Data and certs live in the
host `./data` and `./config` directories for easy inspection. Migrations run
automatically on container start; `APP_ENV=dev` keeps the session cookie non-`Secure`
so login works over plain `http://localhost`.

## Frontend

The SPA lives in `app/frontend` (React 19, Vite 6, Tailwind v4, TanStack Query) and
is **baked into the image** at build time. For live UI work, run the Vite dev server:

```bash
cd app/frontend
npm ci
npm run dev        # Vite dev server (proxies the API)
npx tsc --noEmit   # typecheck
npm run build      # production build (what the image build runs)
```

Rebuild the image (`… up -d --build`) to pick up UI changes inside the container.

## Tests

**PHP unit tests** (PHPUnit) — run on a host with PHP 8.3 + Composer:

```bash
cd app
composer install
vendor/bin/phpunit
```

**DAV smoke tests** (requires a running stack):

```bash
CONTAINER=davyn bash tests/smoke.sh
# Custom target / credentials:
BASE_URL=http://localhost:8080 USERNAME=admin PASSWORD=ChangeMe123! CONTAINER=davyn bash tests/smoke.sh
```

**Release check** — version consistency, UI build/typecheck, compose validation and
(if the stack is up) the health check and smoke tests:

```bash
bash scripts/release-check.sh
```

CI (`.github/workflows/ci.yml`) runs the PHP tests, the frontend build/typecheck,
and the DAV smoke tests (against the locally built image) on every push and PR.

## Database & migrations

SQLite only. Each `app/migrations/NNNN_*.sql` runs exactly once inside a
transaction (`MigrationRunner`), automatically on container start. Add a new file
with the next number; additive, nullable columns keep existing data safe. To apply
manually:

```bash
docker compose exec davyn php bin/migrate.php
```

See [docs/CLI.md](CLI.md) for the full CLI reference.
