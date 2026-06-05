-- Calendars
CREATE TABLE IF NOT EXISTS calendars (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    uri          TEXT    NOT NULL,
    display_name TEXT    NOT NULL,
    description  TEXT    NULL,
    color        TEXT    NULL,
    timezone     TEXT    NULL,
    sync_token   INTEGER NOT NULL DEFAULT 1,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (user_id, uri)
);

CREATE TABLE IF NOT EXISTS calendar_objects (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    calendar_id      INTEGER NOT NULL,
    uri              TEXT    NOT NULL,
    uid              TEXT    NULL,
    ics              TEXT    NOT NULL,
    etag             TEXT    NOT NULL,
    size             INTEGER NOT NULL,
    component_type   TEXT    NULL,
    first_occurrence TEXT    NULL,
    last_occurrence  TEXT    NULL,
    created_at       TEXT    NOT NULL,
    updated_at       TEXT    NOT NULL,
    deleted_at       TEXT    NULL,
    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
    UNIQUE (calendar_id, uri)
);

CREATE TABLE IF NOT EXISTS calendar_changes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    calendar_id INTEGER NOT NULL,
    object_uri  TEXT    NOT NULL,
    change_type TEXT    NOT NULL CHECK (change_type IN ('created', 'updated', 'deleted')),
    sync_token  INTEGER NOT NULL,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE
);

-- Address books
CREATE TABLE IF NOT EXISTS addressbooks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    uri          TEXT    NOT NULL,
    display_name TEXT    NOT NULL,
    description  TEXT    NULL,
    sync_token   INTEGER NOT NULL DEFAULT 1,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (user_id, uri)
);

CREATE TABLE IF NOT EXISTS addressbook_objects (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    addressbook_id  INTEGER NOT NULL,
    uri             TEXT    NOT NULL,
    uid             TEXT    NULL,
    vcard           TEXT    NOT NULL,
    etag            TEXT    NOT NULL,
    size            INTEGER NOT NULL,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL,
    deleted_at      TEXT    NULL,
    FOREIGN KEY (addressbook_id) REFERENCES addressbooks(id) ON DELETE CASCADE,
    UNIQUE (addressbook_id, uri)
);

CREATE TABLE IF NOT EXISTS addressbook_changes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    addressbook_id INTEGER NOT NULL,
    object_uri     TEXT    NOT NULL,
    change_type    TEXT    NOT NULL CHECK (change_type IN ('created', 'updated', 'deleted')),
    sync_token     INTEGER NOT NULL,
    created_at     TEXT    NOT NULL,
    FOREIGN KEY (addressbook_id) REFERENCES addressbooks(id) ON DELETE CASCADE
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_calendars_user_id             ON calendars        (user_id);
CREATE INDEX IF NOT EXISTS idx_calendar_objects_calendar_id  ON calendar_objects (calendar_id);
CREATE INDEX IF NOT EXISTS idx_calendar_objects_uid          ON calendar_objects (uid);
CREATE INDEX IF NOT EXISTS idx_calendar_changes_token        ON calendar_changes (calendar_id, sync_token);

CREATE INDEX IF NOT EXISTS idx_addressbooks_user_id              ON addressbooks        (user_id);
CREATE INDEX IF NOT EXISTS idx_addressbook_objects_addressbook_id ON addressbook_objects (addressbook_id);
CREATE INDEX IF NOT EXISTS idx_addressbook_objects_uid           ON addressbook_objects (uid);
CREATE INDEX IF NOT EXISTS idx_addressbook_changes_token         ON addressbook_changes (addressbook_id, sync_token);
