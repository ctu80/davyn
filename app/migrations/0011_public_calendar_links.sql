CREATE TABLE IF NOT EXISTS public_calendar_links (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    calendar_id  INTEGER NOT NULL,
    token_hash   TEXT    NOT NULL UNIQUE,
    token_prefix TEXT    NOT NULL,
    name         TEXT    NOT NULL,
    created_at   TEXT    NOT NULL,
    revoked_at   TEXT    NULL,
    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_public_links_token ON public_calendar_links (token_hash);
