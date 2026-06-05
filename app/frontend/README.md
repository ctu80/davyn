# Davyn Frontend

Premium React SPA for the Davyn self-hosted CalDAV/CardDAV suite. Built with
Vite + TypeScript + Tailwind v4 + Motion + Radix UI + TanStack Query.

**No Node.js runtime is required in production.** The app is built to static
files (`dist/`) which Caddy serves under `/app/*`. The PHP/SabreDAV backend and
all existing APIs are unchanged.

## Build

```bash
cd app/frontend
npm ci          # or: npm install
npm run build   # outputs to app/frontend/dist/
```

`docker-compose.yml` bind-mounts `./app/frontend/dist` into the Caddy container
at `/var/www/html/frontend`. After a rebuild the new files are served
immediately (no container restart needed). If you change `vite.config.ts`,
`docker-compose.yml`, or the Caddyfile, recreate Caddy:

```bash
docker compose up -d --force-recreate caddy
```

## Develop

```bash
npm run dev        # Vite dev server (proxies /api, /dav, /login to :8080)
npm run typecheck  # tsc --noEmit
```

The dev server proxies API calls to the running Docker stack on `localhost:8080`,
so start the backend first with `docker compose up -d`.

## Routing & auth

- Served at `/app/*`; `/admin*` redirects into `/app/admin`.
- `/login` stays server-rendered (PHP) — session + CSRF are unchanged.
- The SPA bootstraps its CSRF token from `/api/user/me` and sends it as
  `X-CSRF-Token`. On any `401` it redirects to `/login`.
- Sensitive admin actions transparently trigger the step-up reauth modal.

## Structure

```
src/
  api/         TanStack Query hooks + types (user.ts, admin.ts, types.ts)
  components/
    ui/        Davyn design system (Button, Card, Dialog, Select, Tabs, …)
    layout/    AppShell, Sidebar, Topbar, Brand
  routes/      Pages (Dashboard, Calendar, Contacts, …, admin/*)
  lib/         api client, theme, formatting, nav, cn
  styles/      globals.css (Tailwind v4 + design tokens)
```
