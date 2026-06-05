CREATE TABLE IF NOT EXISTS api_tokens (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    name         TEXT    NOT NULL,
    token_hash   TEXT    NOT NULL UNIQUE,
    token_prefix TEXT    NOT NULL,
    scopes       TEXT    NOT NULL DEFAULT '',
    created_at   TEXT    NOT NULL,
    last_used_at TEXT    NULL,
    revoked_at   TEXT    NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_api_tokens_hash ON api_tokens (token_hash);
