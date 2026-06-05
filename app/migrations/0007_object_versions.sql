CREATE TABLE IF NOT EXISTS object_versions (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    object_type         TEXT    NOT NULL CHECK (object_type IN ('calendar','addressbook')),
    object_id           INTEGER NOT NULL,
    collection_id       INTEGER NOT NULL,
    object_uri          TEXT    NOT NULL,
    content             TEXT    NOT NULL,
    etag                TEXT    NOT NULL,
    version_created_at  TEXT    NOT NULL,
    reason              TEXT    NULL
);
CREATE INDEX IF NOT EXISTS idx_object_versions_lookup ON object_versions (object_type, object_id);
CREATE INDEX IF NOT EXISTS idx_object_versions_uri    ON object_versions (object_type, collection_id, object_uri);
