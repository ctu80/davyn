-- Store the share token so an existing public link can be displayed/copied
-- again later (the link is a read-only feed capability, not a password). The
-- token_hash column is kept for the public-calendar lookup path.
ALTER TABLE public_calendar_links ADD COLUMN token TEXT;

-- Clean up any pre-existing duplicates so the unique index below can be
-- created: keep the newest active link per calendar and revoke the rest.
UPDATE public_calendar_links
SET revoked_at = '1970-01-01T00:00:00Z'
WHERE revoked_at IS NULL
  AND id NOT IN (
      SELECT MAX(id)
      FROM public_calendar_links
      WHERE revoked_at IS NULL
      GROUP BY calendar_id
  );

-- Enforce at most one *active* (non-revoked) public link per calendar so the
-- same calendar can't be shared twice. Revoked rows are excluded so a link can
-- be revoked and a fresh one created later.
CREATE UNIQUE INDEX IF NOT EXISTS idx_public_links_active_cal
    ON public_calendar_links (calendar_id)
    WHERE revoked_at IS NULL;
