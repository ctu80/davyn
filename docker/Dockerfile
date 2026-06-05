# Davyn — all-in-one image: PHP-FPM + SabreDAV backend + built React/Vite UI + Caddy,
# supervised together by s6-overlay.
#
# A normal deployment pulls THIS image and runs it; no Node, PHP or Composer is
# needed on the host. The UI is built in a Node stage and baked in; PHP deps are
# installed (production-only) in a Composer stage; the runtime stage runs php-fpm
# and Caddy as s6-supervised services (see docker/s6/ and docker/scripts/davyn-init.sh).

# ── Stage 1: build the React/Vite UI ────────────────────────────────────────
FROM node:20-bookworm-slim AS frontend
WORKDIR /ui
COPY app/frontend/package.json app/frontend/package-lock.json ./
RUN npm ci
COPY app/frontend/ ./
RUN npm run build           # → /ui/dist

# ── Stage 2: install PHP dependencies (no dev deps in the shipped image) ─────
FROM composer:2 AS vendor
WORKDIR /app
COPY app/composer.json app/composer.lock ./
RUN composer install --no-interaction --no-progress --no-dev --optimize-autoloader

# ── Stage 3: runtime (Debian 13 "Trixie") ──────────────────────────────────
FROM php:8.3-fpm-trixie

LABEL org.opencontainers.image.title="Davyn" \
      org.opencontainers.image.description="Self-hosted CalDAV & CardDAV suite (all-in-one)" \
      org.opencontainers.image.version="0.5.0-alpha" \
      org.opencontainers.image.source="https://github.com/ctu80/davyn" \
      org.opencontainers.image.licenses="Apache-2.0"

# Caddy binary (web server / router).
COPY --from=caddy:2-alpine /usr/bin/caddy /usr/bin/caddy

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libsqlite3-dev \
        sqlite3 \
        xz-utils \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_sqlite

# s6-overlay — supervises php-fpm and Caddy as separate services (auto-restart on
# crash, proper PID 1 / zombie reaping, graceful shutdown). x86_64 only for now.
ARG S6_OVERLAY_VERSION=3.2.3.0
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz /tmp/
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-x86_64.tar.xz /tmp/
RUN tar -C / -Jxpf /tmp/s6-overlay-noarch.tar.xz \
    && tar -C / -Jxpf /tmp/s6-overlay-x86_64.tar.xz \
    && rm -f /tmp/s6-overlay-*.tar.xz

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-davyn.ini

# Application code, production vendor and the built UI.
WORKDIR /var/www/html
COPY app/public ./public
COPY app/src ./src
COPY app/migrations ./migrations
COPY app/bin ./bin
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /ui/dist ./frontend

# Caddy config, the s6 service tree, and the one-shot init (dir prep + migrations +
# conditional-HTTPS stub generation).
COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY docker/s6/ /etc/s6-overlay/
COPY docker/scripts/davyn-init.sh /etc/davyn/init.sh
RUN chmod +x /etc/davyn/init.sh \
             /etc/s6-overlay/s6-rc.d/php-fpm/run \
             /etc/s6-overlay/s6-rc.d/php-fpm/finish \
             /etc/s6-overlay/s6-rc.d/caddy/run \
    && chown -R www-data:www-data /var/www/html

# Built-in defaults for the image's data/config layout. Override via .env.
ENV DB_DRIVER=sqlite \
    DB_PATH=/var/lib/davyn/davyn.sqlite \
    CERT_DIR=/config/certs \
    CERT_FILE=davyn.crt \
    KEY_FILE=davyn.key \
    HTTP_PORT=8080 \
    HTTPS_PORT=8443

# Persisted state lives here (named volumes in docker-compose.yml):
#   /var/lib/davyn — SQLite DB, backups, exports, imports
#   /config        — TLS certificate store
VOLUME ["/var/lib/davyn", "/config"]

# The container listens on 8080 (HTTP) and, once a cert is installed, 8443 (HTTPS).
EXPOSE 8080 8443

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -fsS http://localhost:8080/health >/dev/null || exit 1

# s6-overlay is PID 1; it brings up init-davyn (oneshot) then php-fpm + caddy (longruns).
ENTRYPOINT ["/init"]
