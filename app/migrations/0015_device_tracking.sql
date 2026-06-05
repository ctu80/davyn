-- Device/session tracking: remember where logins and DAV syncs came from.
ALTER TABLE web_sessions ADD COLUMN ip TEXT NULL;

ALTER TABLE app_passwords ADD COLUMN last_ip TEXT NULL;
ALTER TABLE app_passwords ADD COLUMN last_user_agent TEXT NULL;
