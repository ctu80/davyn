CREATE TABLE IF NOT EXISTS web_sessions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    session_id_hash TEXT    NOT NULL UNIQUE,
    user_agent      TEXT    NULL,
    created_at      TEXT    NOT NULL,
    last_seen_at    TEXT    NOT NULL,
    revoked_at      TEXT    NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_web_sessions_user ON web_sessions (user_id);
