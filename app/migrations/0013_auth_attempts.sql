CREATE TABLE IF NOT EXISTS auth_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    scope        TEXT    NOT NULL,   -- 'login' | 'dav' | 'bearer'
    identifier   TEXT    NOT NULL,   -- lowercased username / client IP
    attempted_at TEXT    NOT NULL,
    success      INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_auth_attempts_lookup ON auth_attempts (scope, identifier, attempted_at);
