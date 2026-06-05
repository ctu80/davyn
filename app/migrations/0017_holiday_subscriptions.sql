-- Per-user holiday calendar subscriptions.
-- Each row links a user + an allowlisted provider (country/region) to a generated,
-- read-only calendar whose holiday events are rolled forward year by year.
CREATE TABLE IF NOT EXISTS holiday_calendar_subscriptions (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id           INTEGER NOT NULL,
    calendar_id       INTEGER NOT NULL,
    provider_key      TEXT    NOT NULL,        -- allowlisted registry key, e.g. 'DE-BW'
    country_code      TEXT    NOT NULL,        -- 'DE'
    region_code       TEXT    NULL,            -- 'BW' or NULL for a national calendar
    locale            TEXT    NOT NULL DEFAULT 'de_DE',
    years_back        INTEGER NOT NULL DEFAULT 1,
    years_ahead       INTEGER NOT NULL DEFAULT 2,
    enabled           INTEGER NOT NULL DEFAULT 1,
    last_generated_at TEXT    NULL,
    last_year_to      INTEGER NULL,            -- highest year generated (rolling guard)
    created_at        TEXT    NOT NULL,
    updated_at        TEXT    NOT NULL,
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
    UNIQUE (user_id, provider_key)
);

CREATE INDEX IF NOT EXISTS idx_holiday_subs_user ON holiday_calendar_subscriptions (user_id);
