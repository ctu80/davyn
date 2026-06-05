# Deployment

Davyn ships as a single self-contained Docker image (`davyn`) that bundles the PHP
runtime, the SabreDAV backend, the built React UI and Caddy. A normal deployment
just pulls that image and runs it — **no Node, PHP or Composer on the host**. This
is an **alpha** release; review the [security checklist](#security-checklist)
before exposing it to the internet.

## Requirements

- A Linux host with **Docker** and the **Docker Compose** plugin.

## Install (pre-built image)

You only need `docker-compose.yml` and `.env` on the host.

```bash
# 1. Get the two files (clone, or download them from the repository)
git clone https://github.com/ctu80/davyn.git && cd davyn   # or just copy the two files

# 2. Configure
cp .env.example .env
#   Set APP_SECRET (required in production):  openssl rand -hex 32
#   Set BASE_URL to your public URL if you have one.

# 3. Pull and start
docker compose pull
docker compose up -d
```

Davyn is now reachable on **http://<host>:8080** (or the `HOST_HTTP_PORT` you
configured). The database migrates itself on first start; open **/setup** to create
the first administrator — see the [README](../README.md#first-run-setup).

> The image is `ghcr.io/<owner>/davyn:0.5.0-alpha`. Until it is published to GHCR,
> build it locally instead (same result) — see [Build from source](#build-from-source).

## Build from source

Builds the same image locally; the Node UI build and Composer install happen inside
the image, so the host still only needs Docker.

```bash
git clone https://github.com/ctu80/davyn.git && cd davyn
cp .env.example .env
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build
```

For live-reload development (bind-mounted source, host-visible data, tests) see
[development.md](development.md).

## Data & persistence

State lives in two named Docker volumes:

| Volume | Mounted at | Contents |
|---|---|---|
| `davyn_data` | `/var/lib/davyn` | SQLite DB, backups, import/export staging |
| `davyn_config` | `/config` | Internal-HTTPS certificate store |

Back up the `davyn_data` volume to keep your content — see
[backup-restore.md](backup-restore.md).

## Updating

```bash
# Bump the image tag in docker-compose.yml (or use the moving alpha tag), then:
docker compose pull
docker compose up -d
```

Migrations run automatically on start. Migrations are additive and transactional;
taking a backup before a major update is still recommended.

## Ports

The container listens on **8080** (HTTP) and **8443** (HTTPS, optional). Change the
**published** host ports via `HOST_HTTP_PORT` / `HOST_HTTPS_PORT` in `.env`. For
public access, terminate TLS on a [reverse proxy](reverse-proxy.md) rather than
exposing 8080 directly.

## Security checklist

- [ ] `APP_ENV=production` and a strong `APP_SECRET` (≥ 32 chars) are set. The app
      refuses to start in production without a secret.
- [ ] Davyn is reached over **HTTPS** (reverse proxy or the internal cert), so the
      secure session cookie is actually sent. Over plain HTTP set
      `COOKIE_SECURE=false` for testing only.
- [ ] `BASE_URL` is set to the public URL so DAV setup links and QR codes are correct.
- [ ] The `davyn_data` volume is backed up.
- [ ] DAV clients use **app passwords**, not the main account password.

## Troubleshooting

| Symptom | Check |
|---|---|
| `docker compose pull` fails | The image may not be published yet, or the package is private — see the GHCR notes in [the release workflow](../.github/workflows/release-image.yml). Build locally instead. |
| Container unhealthy on first boot | `docker compose logs davyn`; the entrypoint logs migration and Caddy startup. |
| Login works over HTTP but not after enabling HTTPS | Cookie is `Secure`; make sure you actually browse via `https://`. |
| `/health` shows `"status":"degraded"` | DB unreachable — inspect `docker compose logs davyn`. |

Verify a running instance at any time:

```bash
curl -s http://localhost:8080/health
# {"app":"Davyn","version":"0.5.0-alpha","status":"ok",...}
```
