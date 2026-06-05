CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT    NOT NULL UNIQUE,
    display_name    TEXT    NOT NULL,
    password_hash   TEXT    NOT NULL,
    role            TEXT    NOT NULL CHECK (role IN ('admin', 'user', 'read_only')),
    is_active       INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL,
    last_login_at   TEXT    NULL
);
