-- Record the originating IP on each audit-log entry.
ALTER TABLE activity_log ADD COLUMN ip TEXT NULL;
