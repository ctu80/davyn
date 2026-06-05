# Connecting CalDAV / CardDAV clients

Davyn speaks standard CalDAV and CardDAV, so it works with DAVx⁵ (Android),
Thunderbird, Apple Calendar/Contacts, Evolution and similar clients.

## 1. Create an app password

DAV clients should authenticate with a dedicated **app password**, never your web
login password. App passwords work for DAV Basic Auth only (not the web UI).

- **Web UI:** Account → App Passwords → create one, copy the generated secret.
- **CLI:**
  ```bash
  docker compose exec davyn php bin/create-app-password.php \
    --username alice --name "Phone (DAVx5)" --password "GeneratedSecretHere"
  ```

## 2. Server URL

Use your public base URL (behind a reverse proxy) or `http://<host>:8080` for
local testing. The DAV entry point is **`/dav/`**:

```
https://dav.example.com/dav/
```

Clients that support auto-discovery can also use the bare domain — the
`/.well-known/caldav` and `/.well-known/carddav` paths redirect to `/dav/`.

| What | URL |
|---|---|
| DAV root (discovery) | `…/dav/` |
| Principal | `…/dav/principals/<username>/` |
| A calendar | `…/dav/calendars/<username>/<collection>/` |
| An address book | `…/dav/addressbooks/<username>/<collection>/` |
| A shared collection | `…/dav/calendars/<you>/shared-<owner>-<collection>/` |

## 3. Client specifics

**DAVx⁵ (Android)**
1. Add account → "Login with URL and user name".
2. Base URL: `https://dav.example.com/dav/`, username, app password.
3. DAVx⁵ discovers calendars and address books automatically.

**Thunderbird**
- Calendars: New Calendar → On the Network → CalDAV → location
  `https://dav.example.com/dav/calendars/<username>/<collection>/`.
- Contacts: the CardBook add-on, pointed at the addressbook URL above.

**Apple Calendar / Contacts (macOS / iOS)**
- Add a CalDAV/CardDAV account, "Advanced", server address
  `dav.example.com`, account path `/dav/principals/<username>/`, app password.

## Sharing over DAV

Collections shared to you (via the web UI) appear automatically as
`shared-<owner>-<collection>` and sync like any other collection. Permissions are
enforced on the DAV layer: `read_only` allows PROPFIND/GET but rejects PUT/DELETE
with `403`; `read_write` allows changes. Creating or changing shares over DAV is
not supported — manage all shares in Davyn's web UI.

## Limitations (alpha)

Basic PUT/GET/DELETE/PROPFIND and sync work for events, tasks and contacts.
Recurring-event edge cases and scheduling/free-busy are not fully evaluated yet;
`MKCALENDAR` and collection-level DELETE over DAV return `501` (create and delete
collections in the web UI instead).
