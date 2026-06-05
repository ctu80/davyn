-- Add generated_type column to calendars to mark auto-generated read-only calendars
ALTER TABLE calendars ADD COLUMN generated_type TEXT NULL;
