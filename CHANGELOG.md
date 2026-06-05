# Changelog

All notable changes to Davyn are documented here. This project is in **alpha**;
breaking changes between releases are possible.

## 0.5.0-alpha — first public release

The first public alpha of Davyn, a self-hosted CalDAV & CardDAV suite.

- **CalDAV & CardDAV** server built on [SabreDAV](https://sabre.io/) — works with
  DAVx⁵, Thunderbird, Apple Calendar/Contacts and other standard clients.
- **Web UI** — a React app for calendars, contacts, global search, import/export,
  sharing and administration.
- **Sharing** — share calendars and address books between users (`read_only` /
  `read_write`), enforced in the UI and over DAV.
- **Generated calendars** — automatic birthday calendars from contacts and
  subscribable public-holiday calendars (via [Yasumi](https://github.com/azuyalabs/yasumi)).
- **First-run setup wizard** (`/setup`) for the initial admin — no default credentials.
- **Backups** — scheduled and manual SQLite backups with retention and restore.
- **Internal HTTPS / TLS** manager and reverse-proxy friendly deployment.
- **All-in-one Docker image** (PHP-FPM + built UI + Caddy, supervised by s6-overlay)
  on Debian 13 — pull and run, no Node/PHP/Composer on the host.

[Apache-2.0](LICENSE) licensed.
