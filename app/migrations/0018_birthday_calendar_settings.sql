-- Per-user birthday calendar settings: the enable flag plus generation bookkeeping.
-- The generated "Birthdays" calendar itself lives in `calendars` (generated_type='birthdays');
-- this table only tracks whether the feature is on and when it was last generated.
-- No row for a user ⇒ treated as enabled (feature works out of the box; the calendar
-- is auto-created the first time a contact with a BDAY is synced).
CREATE TABLE IF NOT EXISTS birthday_calendar_settings (
    user_id           INTEGER PRIMARY KEY,
    enabled           INTEGER NOT NULL DEFAULT 1,
    calendar_id       INTEGER NULL,
    last_generated_at TEXT    NULL,
    created_at        TEXT    NOT NULL,
    updated_at        TEXT    NOT NULL,
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE SET NULL
);
