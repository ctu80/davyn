# Reverse proxy & HTTPS

For any internet-facing deployment, put Davyn behind a reverse proxy that
terminates public TLS (Let's Encrypt). **Davyn does not manage public
certificates** — point your proxy at Davyn's HTTP (`:8080`) or internal-HTTPS
(`:8443`) port.

Works with **any** proxy: Nginx Proxy Manager, Traefik, Caddy, nginx, Apache, …

## Quick setup (proxy → Davyn over HTTP)

1. Publish only what the proxy needs. Either keep `8080` bound to localhost, or
   place Davyn and the proxy on the same Docker network.
2. In your proxy, create a host for your domain (e.g. `dav.example.com`) and
   forward to `http://<davyn-host>:8080`.
3. Enable Let's Encrypt on the proxy for that domain.
4. In Davyn, set **`BASE_URL=https://dav.example.com`** (in `.env` or under
   **Settings → Security → Public / Base URL**) so DAV setup links, public
   calendar links and QR codes use the correct external address.
5. Keep **`APP_ENV=production`** so the session cookie is `Secure`. Because users
   reach Davyn over HTTPS at the proxy, the browser sends the cookie correctly
   even though the proxy→Davyn hop is plain HTTP.

## Encrypting the proxy → Davyn hop (optional)

Davyn can also serve HTTPS on `:8443` using a certificate you install under
**Settings → Security** (self-signed or your own). This is meant only for the
internal hop, not as public PKI.

- Point the proxy upstream at `https://<davyn-host>:8443`.
- With a **self-signed** upstream cert, the proxy must trust Davyn's certificate
  (or disable upstream verification). This encrypts the hop but does not provide
  public trust.
- HTTP never depends on the certificate: Caddy only emits the HTTPS block when a
  valid cert/key pair exists (and `caddy validate` passes), so a missing or
  broken certificate can never take the site down.
- Installing, replacing or removing a certificate needs a manual restart:
  `docker compose restart caddy`.

### Force HTTPS

Once a valid certificate is installed you can disable plain HTTP under
**Settings → Security → Force HTTPS**: Caddy then 308-redirects `:8080` to HTTPS.
The toggle is greyed out until HTTPS is configured and is fail-safe — if the cert
is later removed, plain HTTP automatically returns so you cannot be locked out.
It also needs `docker compose restart caddy`.

## Notes

- `/.well-known/caldav` and `/.well-known/carddav` already 301-redirect to `/dav/`,
  so clients that probe well-known URLs discover the DAV endpoint automatically.
- Davyn sends hardened security headers (CSP, `X-Frame-Options: DENY`,
  `X-Content-Type-Options: nosniff`, HSTS). HSTS only takes effect once served
  over TLS.
