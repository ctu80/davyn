CREATE TABLE IF NOT EXISTS app_passwords (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    name            TEXT    NOT NULL,
    password_hash   TEXT    NOT NULL,
    last_used_at    TEXT    NULL,
    created_at      TEXT    NOT NULL,
    revoked_at      TEXT    NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (user_id, name)
);
