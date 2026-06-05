-- Sharing: admin-managed calendar and addressbook shares
CREATE TABLE IF NOT EXISTS collection_shares (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_type TEXT    NOT NULL CHECK (collection_type IN ('calendar','addressbook')),
    collection_id   INTEGER NOT NULL,
    user_id         INTEGER NOT NULL,
    permission      TEXT    NOT NULL CHECK (permission IN ('owner','read_write','read_only','none')),
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL,
    UNIQUE (collection_type, collection_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_collection_shares_user
    ON collection_shares (user_id, collection_type);

CREATE INDEX IF NOT EXISTS idx_collection_shares_collection
    ON collection_shares (collection_type, collection_id);
