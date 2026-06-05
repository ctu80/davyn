-- Per-user preferences: own language and theme. NULL means "follow the
-- instance default" (app_meta.default_locale / default_theme). Global branding
-- (instance name, accent colour) stays admin-only and is not stored here.
ALTER TABLE users ADD COLUMN locale TEXT NULL;
ALTER TABLE users ADD COLUMN theme  TEXT NULL;
