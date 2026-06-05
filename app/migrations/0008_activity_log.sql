CREATE TABLE IF NOT EXISTS activity_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id   INTEGER NULL,
    action          TEXT    NOT NULL,
    target_type     TEXT    NULL,
    target_id       TEXT    NULL,
    summary         TEXT    NOT NULL,
    created_at      TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_activity_log_created ON activity_log (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_activity_log_actor   ON activity_log (actor_user_id);
