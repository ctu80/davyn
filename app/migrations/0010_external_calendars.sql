CREATE TABLE IF NOT EXISTS external_calendars (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    uri             TEXT    NOT NULL,
    display_name    TEXT    NOT NULL,
    source_url      TEXT    NOT NULL,
    last_refresh_at TEXT    NULL,
    last_error      TEXT    NULL,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (user_id, uri)
);
